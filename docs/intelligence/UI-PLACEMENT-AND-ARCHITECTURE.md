# Intelligence — UI placement and architecture

Status: **PROPOSED, nothing implemented.** Companion to `PHASE0-1-PLAN.md`. Routes and components below were read from
`g2gf/hooks/content-map-*.ts`, `g2gf/app/**`, `g2gb/routes/api.php` and the cited services. Row counts are quoted from earlier
audits and were **not** measured. Worked examples use `<n>` placeholders on purpose: no figure here is a real finding.

## 0. Facts about the application that decide the architecture

1. **Navigation is data, not code.** The sidebar comes from `tblmenumaster_g2g`, filtered by the caller's rights
   (`g2gf/services/navigation/sidebar.ts`); `hooks/content-map-*.ts` then binds each `accessLink` to a component. A new menu
   item therefore needs a **database row plus a rights decision**, and `docs/phase3/13-current-state.md` records the
   rights-matrix population (item 9b) as a *review gate, not applied*. New top-level navigation is expensive and risky here.
2. **There is already a landing page per role.** `/dashboard` (menu id 300) renders `MainDashboard`, which switches between
   `HrDashboard` (`profile:admin,hr` guard on `/api/dashboard/hr/*`) and `MeDashboard` (`api.token`, `/api/dashboard/me/*`).
   The HR dashboard already has an **"AI Insights" slot that is an honest empty placeholder** (`hr-dashboard.tsx:443-461`).
3. **The product has two unrelated audiences.** People/capability (Org, Competency, Talent, LMS, HRIT, Task) and
   sales/market (CRM, GTM, Signals, Portfolio). Different users, different data, different rights.
4. **A module called "Capability Intelligence" already exists** (module id 2, slug `/module/capability-intelligence/*`, Command
   Center at `.../dashboard`). **Agentic AI** (module id 186) also has *Analytics* and *Reflection* screens, but those are
   about agent runs, not business data. The word "Insights/Intelligence" is therefore already in use; avoid a third meaning.
5. **Much of the logic exists, and some of it disagrees with itself** (§7).

## 1. The three options, judged against those facts

| | A. One central screen | B. A screen in every module | C. Hybrid |
|---|---|---|---|
| Fit with G2G | Poor. The two audiences (HR vs sales) have nothing in common; a combined page serves neither. A central page also tempts re-implementing every module's numbers | Poor. 12+ modules, most with data too thin to fill a page (task: 22 completed; succession: 0 plans; payroll: 1 payslip, all as documented). Each page needs a `tblmenumaster_g2g` row and a rights decision, and the rights matrix is under review | **Good**, if kept lean |
| Navigation cost | Low (one item) | High (one row + rights per module) | **Zero new menu rows in Phase 1** |
| Duplication risk | High (second copy of module KPIs) | High (same expiry/gap figure on several pages, already happening, §7) | Low *if* each insight has one owner and other screens link to it |
| Data honesty | A thin module's gap is hidden in a total | Thin modules show empty dashboards | Thin modules simply show nothing until ready |

**Recommendation: Option C, in a deliberately lean form.**
* **Central = the existing Home dashboard**, not a new Intelligence menu. Its Insights panel becomes a ranked "what needs
  attention" list for HR/admin. It shows the top few items only and links out. This reuses the guard, the hook
  (`use-hr-dashboard.ts`) and the empty-state convention already in place.
* **Contextual = a small number of sections inside screens that already exist**, only where data is ready.
* **Cross-module = only where a relationship is already verified** (the gap → course loop), and it lives on the screen where the
  user acts, not in a separate "cross-module" page.
* **Sales/market stays separate** from the HR Home: it gets its own contextual placements (GTM, CRM), never mixed in.
* A dedicated full-page "Priorities" list is **deferred to Phase 2** because it needs a new menu row and rights approval.

## 2. Navigation tree (where Intelligence lives)

```
Home  /dashboard (menu 300)
 └─ HR/admin view: [Insights panel]  ← central overview (replaces the placeholder); [Data readiness] card
 └─ Employee view: unchanged in Phase 1 (own growth already served by /dashboard/me/growth)

Capability Intelligence (module 2)  /module/capability-intelligence/…
 └─ dashboard (Command Center)   + [Data readiness] section  + [Capability coverage] (Phase 2)
 └─ (no new submenu in Phase 1)
Talent Management (module 3)
 ├─ talent-dashboard             + [Hiring-funnel aging] (Phase 2)
 ├─ employee-profiles            + [Gaps & role fit for this person] (reuses cm-gap-learning-panel)
 └─ certifications               + [Expiry horizon] (single definition, §7)
LMS (module 4)
 ├─ learning/learning-dashboard  + [Learning funnel] (Phase 2; tracking looks broken, verify first)
 └─ training-and-records/capability-gap-inbox  + [Ranked gaps]
HRIT (module 5)
 ├─ attendance-management/attendance-tracking  + [Anomalies] (Phase 2)
 └─ leave-management/leave-dashboard           + [Balance risk] (Phase 2/3)
Task Management (module 204)
 └─ workstream-health, my-tasks      + [Slip warnings] (Phase 2)
Sales side
 ├─ /gtm/command-center, /gtm/deals, /gtm/forecast  + [Next best action]  (Phase 2, check what exists first)
 └─ /module/crm/reports (generic builder)           + [Weighted pipeline] (Phase 2)
Platform  /platform-services/scheduler              + [Pipeline health] (Phase 2)
Phase 2 only, needs menu row + rights:  /module/capability-intelligence/priorities  (full ranked list)
```

## 3. Placement matrix

Readiness: R = ready, P = partial, B = blocked. Priority: P0 prerequisite, P1 first release, P2 next, D defer.
"Source" cites verified tables/services; `⚠` = count quoted from audits, unmeasured.

| # | Page and route | Placement | User | Business question | Verified sources | Displayed | Evidence / drill-down | Action | Related | Ready | Pri |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | **Home** `/dashboard` (`hr-dashboard.tsx`) | Fill existing Insights panel | HR, admin | What needs my attention this week? | outputs of rows 2–5 only; no own queries | Top ≤5 items, each with a one-line reason and count | Each item links to the owning screen's filtered list | Open the owner screen | all | P | **P1** |
| 2 | Home + Command Center `/module/capability-intelligence/dashboard` | **Data readiness** card/section | Admin, HR | Can I trust the capability numbers? | `s_user_jobrole`, `jobrole_competency_map`, `competency_kasba_rating`, `tbluser.reporting_manager_id`, `s_competency_assessments.user_id`, `course_competency_map` ⚠ | Per-measure value, "not measurable yet" when no denominator | "Fix list" opens the offending records | Map roles, rate people, assign managers | Org | **R** | **P1** |
| 3 | Gap Inbox `/module/lms/training-and-records/capability-gap-inbox` | Ranked order + reason line in the existing list | HR, L&D | Which gap first? | `capability_course_requests(+_subjects)`, `jobrole_competency_map.is_mandatory/required_proficiency`, `competency_kasba_rating`, `CompetencyGapController` | Rank + "Mandatory for N people, M unmeasured" | Inputs shown beside rank; people list | Map existing course / commission one (existing dialogs) | Competency, LMS | P (23–46 role mappings ⚠) | **P1** |
| 4 | Certifications `/module/talent-management/certifications` and Home Action Center | One expiry definition + horizon buckets | HR, compliance | Who lapses next, and are we compliant? | `s_competency_certifications`, `…_requirements`, `CertificationCompliance` | Expiring / expired counts by window and department | Rows behind each count | Notify, assign renewal course (existing remediation chain) | Compliance, LMS | **R** (where requirements exist) | **P1** |
| 5 | Employee Profiles `/module/talent-management/employee-profiles` | Section in the existing person view | HR, manager | What is this person's gap and best-fit role? | `/competency/gap`, `/competency/role-fit` (exist), `ProficiencyService` | Existing gap + fit, shown with "unmeasured" count | Item-level required vs measured | Assign course, plan | Competency | P | P2 (already built; surface only) |
| 6 | LMS Dashboard `/module/lms/learning/learning-dashboard` | Funnel panel | L&D | Do enrolments become completions? | `lms_course_enroll` → `lms_content_progress` → `lms_certificates` (1,426 → 1 → 0 ⚠) | Enrolled/started/completed; overdue | Course and learner lists | Nudge, reassign | LMS | P: data fine, **tracking suspect** | P2 |
| 7 | Command Center | Capability coverage by role/department | HR, dept heads | Where are we weakest, and what share is measured? | `ProficiencyService::rollUpMany` | Weakest competencies + coverage % | Per-person breakdown | Plan development | Org, LMS | P | P2 |
| 8 | Attendance `/module/hrit-solutions/attendance-management/attendance-tracking` | Anomaly list | HR, managers | Missing punch-outs, chronic lateness | `hrms_attendances` (~940–990 rows/tenant ⚠), holidays/weekdays | Rule-based exceptions only | The punch rows | Regularise, nudge | Leave | P (holiday/week-off wiring gap documented) | P2 |
| 9 | Workstream health / My Tasks (`task/workstream-health.tsx`, `/module/task-management/my-tasks`) | Slip warnings extending `WorkstreamHealth` | Managers | Which work will miss its date? | `task`, dependencies, `WorkstreamHealth` | Due-soon-unstarted, blocked-by-predecessor | Task and blocker | Nudge, reassign | Competency (skill_id) | P (2,087 of 2,271 PENDING ⚠) | P2 |
| 10 | Talent Dashboard `/module/talent-management/talent-dashboard` | Stage-aging in funnel | Recruiters | Where do candidates stall? | `talent_candidates`, offers, `talentFunnel` | Age per stage | Candidate list (personal data, gated) | Follow up | n/a | P | P2 |
| 11 | Scheduler `/platform-services/scheduler` | Health view | Platform admin | Is anything silently not running? | `g2g_platform_task_runs`, event failures | Last run, repeated failures | Run output | Fix job | all | R once scheduler runs | P2 |
| 12 | GTM Command Center `/gtm/command-center`, Deals `/gtm/deals` | Ranked next actions from existing flags | Sales reps | Which deal needs me today? | `DealHealth` flags, `IcpScorer`, `CustomerHealth` | Ranked flags | Deal + activity | Open deal | CRM | P: **first check whether command-center already ranks** | P2 |
| 13 | CRM Reports `/module/crm/reports` | Weighted pipeline / stage velocity | Sales managers | What will we close? | `crm_opportunities`, `crm_opportunity_stage_history`, `ForecastMath` | Weighted value, stuck deals | Opportunity list | Follow up | GTM | P (CRM volume unknown) | P2 |
| 14 | Leave Dashboard `/module/hrit-solutions/leave-management/leave-dashboard` | Balance-lapse risk | HR | Who will lose leave in March? | `hrms_leave_allocation`, `hrms_emp_leaves`, `LeaveAnalyticsService` | Per-team lapse risk | Balances | Remind | Task capacity | P (5–90 leave rows/tenant ⚠; `leave_type_id` corrupt on 15 of 29 ⚠) | D→P2 |
| 15 | Performance, Mobility & Succession, Offboarding, Payroll | none | HR | n/a | thin or sensitive | n/a | n/a | n/a | n/a | **B** (0 succession plans, 1 payslip ⚠; ratings/exit text sensitive) | **D** |
| 16 | Signals `/signals`, Portfolio `/portfolio` | none new | Sales | n/a | already evidence-graded, deterministic | already shown | already shown | n/a | GTM | n/a | D (feedback loop needs volume) |
| 17 | Agentic AI Analytics/Reflection | untouched | Admin | agent-run quality | `/agentic/analytics/*` | existing | existing | existing | n/a | n/a | **Do not merge** (different meaning) |

## 4. Central experience: Home Insights panel (HR/admin)

* **Location:** the existing "AI Insights" `Widget` in `components/domain/main-dashboard/hr/hr-dashboard.tsx`, fed by a new
  section on the guarded group: `GET /api/dashboard/hr/insights` (same `profile:admin,hr` middleware, same `/hr` section
  convention: *"a slow or failing section degrades only itself"*). Rename the panel "Needs attention"; the current badge "New"
  and the disabled buttons stay disabled until they have a real target.
* **Purpose:** rank cross-module priorities and send the user to the screen that owns each one. It never reproduces a module's
  reports.
* **Content (max 5 rows + one data-quality line):** each row = *finding sentence · count · type label · "Why" · link*.
  The initial producers are only: expiring/expired certificates (row 4), top ranked gap (row 3), and readiness shortfalls (row 2).
* **Data-quality line:** from row 2, e.g. "Capability figures cover `<n>` of `<m>` employees", shown above the list so a
  thin tenant sees the limit before the findings.
* **Duplicate control:** an insight has exactly **one owner service**; the panel only calls owners and de-duplicates by a stable
  `insight_key` (e.g. `cert_expiry`, `gap_priority`, `readiness_roles`). A module screen never recomputes what an owner computes.
* **States:** loading skeleton; *insufficient data* ("Not measurable yet: no role has a competency mapping") instead of 0 or hidden;
  error degrades only this panel; empty-and-healthy ("Nothing needs attention") is distinct from insufficient.

## 5. Contextual sections to build (Phase 1 only), specifications

**5.1 Data readiness (Home card + Command Center section)**
* Heading "How complete is our capability data?". Rows (value, denominator, target, "Why it matters", "Fix list"):
  roles with a competency mapping; employees with at least one rating; mandatory competencies unmeasured; users without a
  reporting manager; assessments/plans with no employee; courses without a competency link; certificates past expiry not marked expired.
* Type: **Measured**. Evidence: record counts; drill-down lists the records. Action: open the owning screen
  (Framework & Role mapping, Assessments, Employee Directory, Course Builder).
* States: no denominator → "Not measurable yet" (never 0%); error per row; tenant isolation by `apiTenantId()`.

**5.2 Ranked gaps (Gap Inbox)**
* Adds a rank and a reason ("Mandatory for `<n>` people; `<m>` unmeasured") to the existing list, sorted by
  shortfall × mandatory × people affected, **using `CompetencyGapController`/`ProficiencyService` outputs; no new arithmetic**.
* Type: **Calculated**. Unmeasured is shown as its own state and never inflates rank. Drill-down: the people and ratings behind it.
* Action: the existing "map existing course" and "dismiss with reason" dialogs. Outcome measurement is **blocked** until
  assignments record which gap they close and rating history exists (4 history rows ⚠), so no "gap closed" claims in Phase 1.

**5.3 Certification expiry (Certifications tab, Command Center tile, Home Action Center)**
* One definition, §7. Buckets 0–30/31–60/61–90 (by date, not stored `status`), by department, with "status disagrees with date" count.
* Type: **Calculated**. Action: existing notify/renewal chain; **no events emitted until the scheduler is approved**.

**5.4 Employee profile gaps (Phase 2, surface-only):** show the existing `/competency/gap` and `/competency/role-fit` results.

## 6. Cross-module opportunities (relationship verified?)

| Connection | Relationship used | Problem it reveals | Verified? | Where | Decision |
|---|---|---|---|---|---|
| Gap → course → completion | `capability_course_requests`, `course_competency_map.competency_id`, `lms_assignments` (source=`competency`) | Gaps with no course; courses that never close gaps | Tables yes; **assignment doesn't record the gap it closes** (documented) | Gap Inbox | Ranking now; effectiveness D |
| Certificate → requirement → department | `s_competency_certification_requirements`, `tbluser.department_id` | Compliance by department | Yes | Certifications/Home | P1 |
| Task skill → competency evidence | `task.skill_id` (66.7% filled ⚠), event projector | Skills evidenced by real work | Partial; task and competency modules use **different context resolvers** (`ResolvesTaskContext` vs `competencyContext`), so authorization must be checked first | Employee Profiles | D until verified |
| Leave ↔ task capacity | `user_id` on both | Over-assignment during leave | Relationship simple; leave data thin | Task capacity | D |
| GTM deal → CRM opportunity | one-way `crm_opportunities.converted_from_gtm_deal_id` | Won-revenue vs activity | Bridge exists, one direction only | GTM/CRM | P2, pick one side or federate explicitly |
| Attendance/leave/performance per person | `user_id` | Individual profiling | Sensitive | n/a | **Do not build** |

## 7. Reuse, and what must be corrected first

| Existing | Use | Needed change |
|---|---|---|
| `HrDashboardService`, `use-hr-dashboard.ts`, `profile:admin,hr` guard | Host the central panel; add an `insights` section | New section only |
| `CompetencyGapController`, `RoleFitController`, `ProficiencyService`, `NineBoxController` | Source of all gap numbers | None; do not re-derive |
| `CapabilityGapResponder`, `RemediationRecommender`, `ScanCertificationExpiry` | Action chain | Unchanged; scan stays unscheduled until approved |
| `RecommendationRepository` / `hpbrain_recommendations` | Persist an insight someone chooses to track | Not used in Phase 1 (computed on read) |
| `InsightWidget` (`components/shared/business`) | Row layout (priority, action) | Needs an evidence/type label field |
| `WorkstreamHealth`, `ForecastMath`, `DealHealth`, `CustomerHealth`, `EvidenceGrader` | Patterns and Phase 2 sources | None |
| Reports engine (`ReportsShell`, scopes `crm`, `task_management`) | Place for ad-hoc analysis | Insights link into it; no second report system |

**Defect to fix before building (the strongest evidence for a single owner).** "Certificates expiring soon" has four definitions:

| Where | Window | Status filter |
|---|---|---|
| `CertificationCompliance::EXPIRING_WINDOW_DAYS` (Certifications tab metrics) | 60 days | excludes revoked/expired |
| `HrDashboardService::actionCenter` | 30 days, hard-coded | **none** (counts revoked) |
| `CommandCenterService` | 30 days, hard-coded | valid/expiring |
| `ScanCertificationExpiry` | T-30 / T-7 (event windows, intentional) | n/a |

So the Home card and the Certifications tab can show different numbers for the same question. Fix: route Home and Command Center through
`CertificationCompliance`. **Difference from `PHASE0-1-PLAN.md` §4.4:** that section proposed *extending* `actionCenter` with new
buckets; this document instead says *first make `actionCenter` and `CommandCenterService` call the canonical class*, then add buckets
there. This changes displayed numbers (the Home count may drop), so it is a **product decision to approve**.

## 8. Insight classes (every item carries one label)

| Class | Meaning | Example | AI allowed? |
|---|---|---|---|
| Measured | Direct count | "`<n>` of `<m>` roles have a mapping" | no |
| Calculated | Deterministic rule/arithmetic | gap rank, expiry window | no |
| Inferred | Rule with a stated assumption | "likely to lapse unused leave" | no |
| AI-worded | Sentence wording only, never a number | narrative paragraph | wording only, via existing `AiModelClient` + usage meter |
| Verified outcome | Evidence after an action | "rating rose after course" | **none until history exists** |

## 9. Rollout, dependencies, risks, tests

| Phase | Ships | Depends on | Key risks | Tests |
|---|---|---|---|---|
| **1** (no new menu rows) | Row 2 readiness; row 4 expiry unification; row 3 ranked gaps; row 1 Home panel | Phase 0 items 0.1–0.2; approval of the expiry-number change | Thin mappings make ranks noisy (state it); NULL-tenant policy; tenant isolation of new endpoint | Fixture-based unit tests per rule; recount-equals-service test; cross-tenant test per endpoint (pattern: `TenantIsolationTest`); ratchet stays green; frontend states (loading/insufficient/error) |
| **2** | Rows 5–14 where data is confirmed; `/priorities` page | Menu row + rights approval; scheduler plan (0.4); live counts | Alert fatigue; employee-monitoring privacy (attendance); CRM vs GTM side choice | As above plus privacy-gate tests for attendance/candidates |
| **3** | Outcome measurement, Signals calibration, win/loss clustering | History volume; assignment↔gap link | Small samples, false causality | Evaluation fixtures with known answers |

Unresolved questions (need your decision): (1) approve changing the Home expiry count to the canonical definition; (2) whether the
Home panel is for HR/admin only (recommended; matches the guard) or also managers (needs a subordinate-scope rule and
`reporting_manager_id`, filled on 0 of 299 users ⚠); (3) the `/priorities` menu row and which roles see it; (4) whether GTM
command-center already ranks actions (read it before building row 12).

## 10. Files likely affected

Existing (edit): `g2gb/app/Services/Dashboard/HrDashboardService.php`, `g2gb/app/Services/Competency/CommandCenterService.php`,
`g2gb/routes/api.php` (one route inside the `dashboard/hr` group), `g2gf/components/domain/main-dashboard/hr/hr-dashboard.tsx`,
`g2gf/hooks/use-hr-dashboard.ts`, `g2gf/components/shared/business/insight-widget.tsx`,
`g2gf/components/domain/competency/cm-command-center.tsx`, `g2gf/components/domain/lms/gap-inbox/capability-gap-inbox.tsx`,
`g2gf/services/lms/capability-gap.ts`, `g2gf/components/domain/competency/cm-certifications.tsx`.
Existing (read-only, reused): `CertificationCompliance`, `CompetencyGapController`, `ProficiencyService`, `RecommendationRepository`.
New (proposed): `g2gb/app/Services/Intelligence/{ReadinessScorecard,InsightRanker}.php`,
`g2gb/app/Http/Controllers/Api/Dashboard/HrInsightsController.php` (or a method on `HrDashboardController`),
`g2gb/tests/Unit/Intelligence/*`, `g2gb/tests/Feature/IntelligenceTenantIsolationTest.php`,
`g2gf/components/domain/main-dashboard/hr/insights-panel.tsx`, `g2gf/services/dashboard/insights.ts`.
No migrations, no new tables, no new menu rows in Phase 1.

## 11. Acceptance criteria (per screen)

* **Home panel:** every row's count equals an independent recount; each row links to its owner screen and that screen shows the same
  number; a tenant with no data shows "insufficient", not zero or empty-healthy; an employee token receives 403; tenant A never sees tenant B.
* **Readiness:** each measure reproducible by the SQL in the plan; "not measurable yet" when the denominator is 0.
* **Ranked gaps:** the rank is reproducible from the displayed inputs; unmeasured items never raise rank; no new arithmetic outside `ProficiencyService`.
* **Expiry:** Home, Command Center and Certifications tab show the **same** number for the same window and filters.
* **All:** no literal sentences or hard-coded figures; every item labelled with its class (§8); no AI-supplied number; existing routes and
  tests unchanged; reviewed by at least one HR user as "something I would act on".
