# Intelligence — what was added (additive only)

Companion to `PHASE0-1-PLAN.md` and `UI-PLACEMENT-AND-ARCHITECTURE.md`. This differs from those plans in one deliberate way:
**nothing existing was replaced.** The Home "AI Insights" card, the Home expiry count and every existing calculation are
untouched; Intelligence sits beside them.

## What the user sees

* **Home (HR / administrator view):** a separate "Intelligence" panel above the existing dashboard: finding count, high-priority
  count, top three findings, and an "Open Intelligence" button. It renders nothing for profiles that are not allowed to see it.
* **`/intelligence` (new page):** summary tiles; one card per finding with **Why it matters**, **Evidence** (counts and
  supporting rows), **Recommended action**, **Expected impact**, severity, how it was derived, and a link to the existing
  screen; then six **data-readiness** checks; then a "how to read this" key and the limits.
  Not in the sidebar (that tree is data in `tblmenumaster_g2g` and changing it is a rights decision); reached from the Home panel or by URL.

## Findings and where each number comes from

| Finding | Rule / source (reused, not copied) |
|---|---|
| Capability gaps waiting for a course decision | `capability_course_requests` where `status = 'open'` (exactly what the Gap Inbox lists) + `capability_course_request_subjects`. Ranked mandatory first, then people affected, then time waiting. Impact line states only that one course decision resolves everyone on the gap, which is how the inbox works |
| Certificate risk | `CertificationCompliance::state()` per row (the Certifications screen's rule, 60-day window), grouped by `hrms_departments.department` |
| Data readiness (6 checks) | roles with competencies (`s_user_jobrole` ↔ `jobrole_competency_map`); employees with a rating (`WorkforceMetricsService::activeEmployeeQuery` ↔ `competency_kasba_rating`); employees with a reporting manager (`tbluser.reporting_manager_id`); assessments with an employee (`s_competency_assessments.user_id`); active courses with a competency (`sub_std_map` ↔ `course_competency_map`); certificates whose stored status matches their expiry date |

Honesty rules enforced in code and tests: an empty denominator is "not measurable", never 0% or healthy; a source that fails is
listed as unavailable and the "nothing found" message says it is not an all-clear; exception text is logged, never returned;
no prediction, no AI call, no write, no scheduled job, no notification.

## Demonstrating it

1. Run the backend against a database you choose. **`g2gb/.env` currently points at a remote host that earlier audits
   describe as live**; use a local copy for a demo. The endpoint only reads, but the rest of the app does not.
2. Run the frontend (`npm run dev` in `g2gf`), sign in as an administrator or HR profile.
3. Open Home: the panel appears above the dashboard. Click "Open Intelligence".
4. An employee profile gets a 403 from the API: the page says it is for HR and administrators; the Home panel stays hidden.
   Tenants with little data show "Not measurable yet" rows, not zeros.

## Existing issues noticed (reported, deliberately NOT changed)

* **Four definitions of "expiring soon"** exist: 60 days in `CertificationCompliance` (Certifications screen, and Intelligence);
  30 days with no status filter in `HrDashboardService::actionCenter`; 30 days with a status filter in `CommandCenterService`;
  T-30/T-7 in `ScanCertificationExpiry`. The Home Action Center and the Certifications screen can disagree. The Intelligence page
  states which window it uses.
* **`days_pending` in `CapabilityCourseRequestController::index`** is `(int) now()->diffInDays($r->first_raised_at)`. On this install
  (Carbon 3.11.4) that returns a negative number for a past date, so the Gap Inbox likely shows zero or negative days. Intelligence
  computes days itself and is tested to be non-negative.
* Pre-existing failing tests (identical on an untouched `HEAD`): 11 Signals/ICP test files fail, and `OfficialSiteDiscoveryTest.php`
  fatals at load (its anonymous `SafeUrlFetcher` subclass no longer matches the class's `fetch()` signature), which aborts a plain
  `phpunit` run. Run files individually until fixed.

## Not included, and why

Sales/GTM, hiring, attendance, task and leave indicators are not shown. They serve other audiences or are not yet reliable enough
to rank; adding them to an HR/admin page would also mix audiences. "Mandatory competency unmeasured by role" needs a reliable
role-to-employee link (job roles are keyed by text in several tables). Outcome measurement ("did the course close the gap") needs
rating history that barely exists (documented: 4 history rows). Each is a future step, not a hidden gap.

## Database

No migration, no new table, no schema change. All reads use existing tables. `reporting_manager_id` exists only where migration
`2026_08_07_120000` has been applied; on a database without it that one check shows "Could not be read" and the rest still answer.
