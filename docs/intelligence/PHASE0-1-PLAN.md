# Intelligence — Phase 0 / Phase 1 plan

Status: **PROPOSED, awaiting approval.** Nothing below except the two test files listed in §1 has been built.
Every row count is quoted from an existing audit, not measured: no database was queried (see §2).

## 1. Verified so far (and how)

| Claim | Method | Result |
|---|---|---|
| F-134 / F-135 (cross-tenant read/edit of compliance records) | Read `instituteDetailController` (G-SEC-29 fix present, `apiTenantId()` used on API branches, `where sub_institute_id` on update/destroy); ran `tests/Feature/TenantIsolationTest.php` on in-memory sqlite | **Fixed in current code.** Cross-tenant delete leaves the foreign row untouched; same-tenant delete still works |
| F-150 (rights matrix, `profile_id` unvalidated) | Read `tblmenumasterG2gController::storeGroupwiseRightsG2g` (ownership check at ~line 258) | **Fixed in current code.** Not covered by a test yet |
| Resolver contract | `TenantIsolationTest`: token tenant beats requested tenant; missing/invalid/expired token resolves to no tenant | Pass |
| Residual: accounts with NULL/0 `tbluser.sub_institute_id` may name any tenant | `ResolvesApiIdentity.php` (documented in the file); pinned by a characterization test | **Open, size unknown** — needs a read-only count of such accounts |
| Other controllers trusting a request tenant | Static scan, 48 files in `app/`; ratchet `tests/Unit/RequestTenantTrustRatchetTest.php` freezes the list | 19 are legacy `lms/*`, plus HRMS leave, dashboards, reports, libraries. **Candidates, not confirmed exploits**: those routes sit behind `auth,session,menu`; the reachable shape is a signed-in user sending `type=API` + another tenant id |
| Scheduler | `schedule:list` with DB forced to sqlite (no job run) | 17 entries registered; `signals:research` absent (config-gated off) |
| Row-count conflicts | Doc provenance | Explained by **different databases**: 91,540 (`cross-repo-audit/07`, DB3 128.199.17.97, which that audit says may not be what the app queries) vs 85,662 (phase3/HRIT audits, app host 202.47.117.220). Not an error in either |

Tests run: `TenantIsolationTest` (5) and `RequestTenantTrustRatchetTest` (2) — 7 pass. The full suite was not run.

## 2. Not verifiable without approval

* Live row counts, NULL-tenant account count, scheduler execution history (`g2g_platform_task_runs`), whether the 62-vs-22 expired-certificate status drift still exists. All need a **read-only** connection you approve.
* Whether the 48-file list contains an exploitable route (needs per-route auth tracing; no data access required, but not done).

## 3. Phase 0 (prerequisites)

| # | Item | Files | Needs approval? |
|---|---|---|---|
| 0.1 | Read-only count script for the readiness metrics (§4), run against an approved target | new `app/Console/Commands/IntelligenceReadinessCounts.php` (SELECT only, `--tenant=` required) | Yes: which DB |
| 0.2 | Decide the NULL-tenant account policy: backfill, or refuse in `ResolvesApiIdentity` | `ResolvesApiIdentity.php`, `ResolvesTaskContext.php`, `WorkspaceController.php:737`, `AiScopeResolver.php:94` | **Yes**: could lock out accounts; needs 0.1 first |
| 0.3 | Work down the 48-file list, LMS first (lms dashboard feeds the funnel) and the dashboard/report controllers; delete each entry from `RequestTenantTrustRatchetTest::BASELINE` as it is fixed | per file | Per batch |
| 0.4 | Scheduler activation plan: which of the 17 jobs may run on which host. **Do not run `schedule:work` locally with the current `.env`**: it has `GTM_OUTREACH_SENDING_ENABLED=true` and targets the remote DB, and the schedule includes `documents:purge-trash --execute`, `reports:send-scheduled`, `gtm:tick`, and `certifications:scan-expiry` (which rewrites `status`) | `routes/console.php` (read only), deployment config | **Yes** |

## 4. Phase 1 features

Principle (matches existing code: `WorkstreamHealth`, `ForecastMath`, `EvidenceGrader`): deterministic rules computed on read, not stored; unmeasured is its own state, never zero or pass; every figure carries the record ids behind it; no AI sets a number.

### 4.1 Data-readiness scorecard — first
* Backend: new `app/Services/Intelligence/ReadinessScorecard.php`; endpoint on the existing dashboard/competency route groups (token tenant via `apiTenantId()`).
* Measures and verified source columns (all tenant-scoped by `sub_institute_id`):

| Measure | Query basis (columns verified in migrations) |
|---|---|
| Roles with a competency mapping | `s_user_jobrole` (soft-delete aware) vs distinct `jobrole_competency_map.jobrole_id` |
| Employees with ≥1 rating | active `tbluser` vs distinct `competency_kasba_rating.user_id` |
| Mandatory requirements unmeasured | `jobrole_competency_map.is_mandatory` × `competency_kasba_item` × `competency_kasba_rating` |
| Users without a reporting manager | `tbluser.reporting_manager_id IS NULL` (column added by migration `2026_08_07_120000`) |
| Assessments / development plans with no subject | `s_competency_assessments.user_id IS NULL`, `s_competency_development_plans.user_id IS NULL` (both nullable) |
| Courses without a competency link | `sub_std_map` (course) vs `course_competency_map.course_id` |
| Certificates past expiry whose status is not `expired` | `s_competency_certifications.expiry_date < today AND status <> 'expired'` |

* UI: `g2gf/components/domain/competency/cm-command-center.tsx` (card) and a one-line summary on `hr-dashboard.tsx`.
* Acceptance: each figure equals an independent SQL recount on a seeded fixture; a tenant with no data shows "not measurable yet", never 0%; tenant B's rows never change tenant A's numbers.

### 4.2 HR Dashboard "AI Insights" panel
* Replace the placeholder at `hr-dashboard.tsx:443-461` with a ranked list fed by 4.1, 4.3 and 4.4. `components/shared/business/insight-widget.tsx` already supports priority + action.
* Backend: add an `insights` section to `HrDashboardService` (reuses its tenant and `empty_reason` conventions).
* The "Generate Report" / "Open Analytics" buttons stay disabled until they have a real target.
* Acceptance: no insight text is a literal; an empty tenant shows the current honest empty state.

### 4.3 Gap prioritisation
* Rank `capability_course_requests` by shortfall × `is_mandatory` × people affected, using `CompetencyGapController` / `ProficiencyService` outputs (no new arithmetic).
* UI: sort + reason line in `capability-gap-inbox.tsx`; service `services/lms/capability-gap.ts`.
* Acceptance: ranking is reproducible from the shown inputs; unmeasured never inflates a rank.

### 4.4 Certification expiry (extend, don't rebuild)
* `HrDashboardService::actionCenter` already counts the next 30 days. Extend with 30/60/90 buckets by department and a "status disagrees with date" count, using **dates, not `status`** (the status column drifts).
* Writing `certification.expiring` events stays with `ScanCertificationExpiry`, which only runs once the scheduler is approved (0.4).

### Recommendations
Persisting an insight as a recommendation uses the existing `hpbrain_recommendations` / `RecommendationRepository` (approve/reject/defer, evidence chain). **No recommendation will be created without approval**; Phase 1 insights are computed on read only.

## 5. Tests required before merge
Unit tests per rule with fixture data (these domains currently have none), a cross-tenant test per endpoint in the style of `TenantIsolationTest`, and the ratchet must stay green.

## 6. Next approval needed
1. Which database (if any) may be queried read-only for 0.1, and which tenant is the reference.
2. Go-ahead for Phase 0.3 (tenant-trust fixes, starting with LMS).
3. Policy decision for 0.2 (NULL-tenant accounts) once counts exist.
4. Go-ahead for Phase 1 §4.1–4.2.
