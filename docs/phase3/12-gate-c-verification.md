# 12 — Gate C verification · an audit of the audit

**Not a re-read.** The 3,378 elements are not re-opened — that is C18, deliberately
traded away. This verifies **the claims the connection plan rests on**, and reports
an **error rate**, not an assurance.

**Status:** `COMPLETE. Trimmed to V4 + V7 by instruction; V2/V5 sampled within V4; V3 and V8 answered inline.`

> ## ⚠️ V6 FOUND FOUR ERRORS IN EIGHT HEADLINE NUMBERS
> Three share a single root cause, and **they run in the opposite direction to
> every previous error in this phase.** Details in §V6. **Do not quote the old
> figures.**

---

# V1 — CLAIM INVENTORY

Extracted from the six module write-ups. The denominator for everything below.

| Class | Count | What it means |
|---|---:|---|
| **STRUCTURAL** — schema, route, column; mechanically checkable | **86** | file:line references, table/column existence, route declarations |
| **NEGATIVE** — *"does not exist / not connected / nothing reads this"* | **31** | **the ones that become build work** |
| **NUMERIC** — a count or percentage | **19** | the quotable figures |
| **BEHAVIOURAL** — what happens at runtime | **14** | needs execution or careful reading |
| **Total claims the plan depends on** | **150** | |

Per module: competency 44 · lms 38 · task 34 · other 33 · talent 31 · organization 23
*(table-bearing rows; a row may carry more than one claim, so 150 is the
claim count, not the row count).*

**39 connection items** across the six write-ups feed §5 of the plan.

---

# V6 — HEADLINE NUMBERS RE-DERIVED BY A SECOND METHOD

**R17 applied first:** every number was checked against an existing artefact before
any new script was written. `c23-result-FULL-912.json` answered the guard figures
without a re-run; `s1-result.json` held the per-table counts.

| # | Number as published | Re-derived | Verdict |
|---:|---|---|---|
| 1 | **283,126** rows string-joined | **283,127** | ❌ **CORRECTED** |
| 2 | **3.0%** capability coverage | **2.7%** | ❌ **CORRECTED — overstated** |
| 3 | **66.7%** `task.skill_id` | 66.7% (1,514 / 2,271; 757 null, sums exactly) | ✅ **CONFIRMED** |
| 4 | **46** guard FAILs | 46 | ✅ **CONFIRMED** |
| 5 | **30** controllers affected | **29** | ❌ **CORRECTED — overstated** |
| 6 | **1,676** routes | **1,683** (router) | ⚠️ **two valid methods — see below** |
| 7 | **912** GET routes | 912 (router) | ✅ **CONFIRMED** |
| 8 | **864** write routes / **430** never audited | **772** write routes | ❌ **CORRECTED — overstated** |
| 9 | **3** confirmed leaks, 2 fixed | 3 (2 fixed, guard-verified green) | ✅ **CONFIRMED** |

## The root cause of three of the four

> **Numerator and denominator computed with different filters.**

### 283,126 → **283,127**

The sum mixed **one populated-column count into a sum of row counts**.
`s_user_jobrole_task` has **85,663 rows**, of which **85,662 have a populated
`jobrole`** — one row has an empty key. I used 85,662 for that table and row counts
for the other three.

**Both figures are defensible; mixing them is not.**

| Statement | Figure |
|---|---:|
| **rows in the four tables** | **283,127** |
| rows with a populated string key | 283,126 |

**Settled 2026-08-07: the headline is 283,126 — ROWS WITH A POPULATED STRING KEY.**
The four tables hold 283,127 rows; one `s_user_jobrole_task` row has an empty
`jobrole`. Either figure is defensible; **the headline is the second, and the
distinction is stated wherever it appears.**

### 3.0% → **2.7%** · the material one

`8 of 264`. The denominator **264** is confirmed correct and well chosen:
`status = 1 AND deleted_at IS NULL`.

**The numerator was not filtered the same way.** Applying the *same* filter to both:

| | |
|---|---:|
| active, not-deleted users | **264** |
| **of those**, with any capability measurement | **7** |
| **coverage** | **2.7%** |

The 8th measured user is **inactive or soft-deleted**. Counting them in the
numerator while excluding them from the denominator **overstates coverage**.

**2.7%, not 3.0%.** Immaterial to any decision — every conclusion drawn from it
("the chain will be structurally correct and visibly empty") holds identically.
**Material to credibility**, which is why it is corrected here.

### 30 → **29** controllers

Not a miscount — a **stale pairing**. "48 FAILs across 30 controllers" was correct.
D-003 fixed `skillLibraryController`'s 2, taking FAILs to **46** — and removing that
controller entirely, taking controllers to **29**. I updated the first number and
carried the second forward.

**46 FAILs across 29 controllers.**

### 864 → **772** write routes

**Method 1** (regex over route files, counting `resource` as 4 writes) gave 864.
**Method 2** (Laravel's own router, the authoritative list of what actually serves)
gives **772**.

**The router wins.** The regex over-counted resource expansion.

⚠️ **The derived claim "430 write routes never audited" is therefore also wrong and
is WITHDRAWN.** It was `864 − 434`. It needs recomputing from the router, per route
file, and **until then no write-route coverage figure is quoted.**

### 1,676 vs 1,683 — both valid, different questions

| Method | Figure | Measures |
|---|---:|---|
| Regex over the six route files | 1,676 | **declarations written** |
| Laravel router | **1,683** | **routes actually registered** |

Neither is wrong. **1,683 is the better figure for "how big is the surface"**,
because it is what serves traffic. R10: state which is meant.

---

## The direction of these errors — and it has flipped

**R11's tally was 13 under-reports, 0 over-reports.** Every previous error made a
finding look *smaller* or a risk look *safer*.

**Three of V6's four go the other way:**

| Error | Direction |
|---|---|
| 3.0% vs 2.7% coverage | **overstated the good news** *(more coverage than exists)* |
| 30 vs 29 controllers | **overstated the finding** |
| 864 vs 772 write routes | **overstated the unaudited surface** |
| 283,126 vs 283,127 | understated by one row — immaterial |

### Why the flip, and why it matters

**The under-reports came from scope-narrowing assumptions during investigation** —
R11's mechanism: a smaller scope is adopted more readily because it looks tidier.

**These over-reports came from presentation.** They are all numbers written *into a
document to be quoted*, and each was assembled by combining figures derived at
different moments with different filters. **Nobody re-derived them end to end until
now, because they had already been "verified" individually.**

> **R19 — a number assembled from other numbers is a NEW claim and must be
> re-derived end to end, with one filter, before it is published.**
> Verifying each input separately does not verify their combination.

**Both directions are wrong.** Under-reporting hid risk; over-reporting inflates
findings a customer or investor will check. The second is the one that costs
credibility.

---

## Corrected figures for §1 of the connection plan

| Use this | Not this |
|---|---|
| **283,126** rows **with a populated key** (283,127 rows exist) | ~~an unqualified 283,127~~ |
| **2.7%** capability coverage (7 of 264) | ~~3.0%~~ |
| **46 guard FAILs across 29 controllers** | ~~30 controllers~~ |
| **1,683 registered routes · 912 GET · 772 with a write verb** | ~~1,676~~ · ~~864~~ |
| **write-route audit coverage: NOT YET DERIVED** | ~~430 never audited~~ |
| 66.7% `task.skill_id` · 3 confirmed leaks, 2 fixed | *(confirmed)* |

---




---

# V4 — 30-CLAIM SAMPLE, RE-DERIVED FROM SCRATCH

Write-ups **not re-read first**. Weighted to NEGATIVE and NUMERIC, as instructed.
V2 (negative claims) and V5 (cross-document contradiction) are **sampled within
this 30**, not run separately.

## Result

| Class | Sampled | Errors | Rate |
|---|---:|---:|---:|
| NUMERIC | 9 | 0 | 0% |
| NEGATIVE — schema | 6 | 0 | 0% |
| NEGATIVE — code / frontend | 9 | **1** | 11% |
| STRUCTURAL | 4 | 0 | 0% |
| BEHAVIOURAL | 2 | 0 | 0% |
| **TOTAL** | **30** | **1** | **3.3%** |

## What re-derived cleanly

**All nine numeric claims, exactly:** `s_user_skill_jobrole` 79,295 ·
`s_jobrole_skills` 62,208 · `s_jobrole_task` 55,961 · `s_users_skills` 3,976 ·
enrolments 1,426 · content-progress **1** · certificates **0** ·
`compliance_relevance` 804 · rights rows 4,879 with **`can_view`=1 on all 4,879 and
`can_add`/`can_edit`/`can_delete`=1 on zero**.

**All six schema negatives:** `s_skill_matrix` has no `sub_institute_id` ·
`s_user_jobrole_task` has no skill/competency column · no `certification_type`
table · no `task_status_history` · `s_jobrole` and `master_skills` have no tenant
column.

**Structural / behavioural:** `app/Events`, `app/Listeners`, `app/Observers` all
absent · `s_user_skill_jobrole` has exactly **two** insert sites
(`RoleMappingController:224`, `SchoolSetupController:408`) · `LibraryController`
accepts `department_id` · `JobroleApiController` joins it to `hrms_departments` ·
`authMiddleware` accepts session **or** token · `contentLibraryControllerOld` is a
live writer of `content_master` · `MyTasksController` validates `delay_category`
against a closed enum · `AssignLearningForm` has no competency reference · the
learning kebab offers only *Mark …* and *Remove Assignment* · **no import control
on any library tab** · `QuickCreateKind` has no `role-mapping`.

> ⚠️ **One of these nearly scored wrong.** "No import control" first returned
> **22 hits** for `import` in `library-tab.tsx` — all ES module statements. Re-run
> against upload/file controls: **none**. The claim holds, but a careless pass
> would have called a true claim false. (R4)

## The one error — competency.md F-2, Command Center navigation

| | |
|---|---|
| **Claimed** | *"`CONTENT_MAP_LOADERS` is keyed `'1','2','3','4','5','204','186'`"*, cited in `cm-command-center.tsx` |
| **Re-derived** | It lives in **`hooks/use-content-map.ts`**, not `cm-command-center.tsx`, and has **eight** keys: `1 2 3 4 5 204 186 50` — **`50` was missing from my list** |

**Two errors in one claim: wrong file, incomplete list.**

**What it changes:** the *substance* — that Command Center tiles navigate to
`/module/competency-management/{submenuId}/{submenuId}` (confirmed at
`cm-command-center.tsx:371`) and land on placeholders — is **not re-derived
either way**, because I did not enumerate the tile submenuIds. **F-2 is downgraded
from a finding to a CANDIDATE (R6)** until someone does.

**Direction: this error OVERSTATED the finding** (a shorter key list makes more
navigation look broken). That is now **four of five** recent errors overstating —
consistent with V6, not with R11's earlier 13–0 under-reporting.

---

# V7 — RECONCILIATION AUDIT · **CLEAN**

Every **ALREADY-APPROVED** verdict across the six write-ups was checked against the
item it points at. **A wrong one silently drops work from Gate D — the only error
class that damages the build.**

| Verdict points at | Specified in `02-domain-model.md`? |
|---|---|
| `course_competency_map` | ✅ 9 references |
| `jobrole_competency_map` | ✅ 8 |
| `competency_kasba_item` | ✅ 11 |
| `jobrole_task_competency_map` | ✅ 5 |
| `certification_competency_map` | ✅ 2 (§10.1, steps 3b/9b) |
| `portal_identity` | ✅ 4 |
| `reporting_manager_id` | ✅ 5 |
| Block-don't-cascade delete (L-06) | ✅ §11 (iii) |
| Import flow (L-10) | ✅ §9, steps 8–9 |
| Text→FK (L-11) | ✅ §10 steps 12–14 |

**No already-approved verdict was found pointing at something that does not cover
the connection claimed. No work is silently dropped.**

---

# V3 — WAS THE C35 CHECKLIST APPLIED? · three lines

**Applied in all six.** Competency 6 forms / 18 files · Organization 4 · LMS 5 ·
Task 4 · Talent 6 · Other 0 forms *(CRM out of scope, Reports has no forms, HRIT's
forms belong to owning modules)*.

**Both directions were checked**, and the inverse case (a column accepted but never
sent) was found **only in Competency** — which is itself a result: `L-01` is one
screen's defect, not a systemic pattern.

**The S-5 class (divergent vocabularies writing one table) has NO replacement
mechanism.** S-5 was retired without one, so that defect class is **currently
unchecked outside the one instance already documented** (Command Center vs Library
approve_status vocabularies). **Recorded as a known gap in coverage, not a finding.**

---

# V8 — COVERAGE, one line per sub-module

| Sub-module | How it was covered |
|---|---|
| Library & Taxonomy | **full write-up + calibration** — 176 rows, 0 structural errors; `library-config.ts`, `library-tab.tsx`, `library-form.tsx`, `LibraryController.php` hand-read |
| Competency Library | **full write-up** — `cm-competency-library.tsx`, `skillLibraryController.php` hand-read |
| Development & Career Path | **calibrated (C1b, 0 of 206)** + hand-read of `cm-development-career.tsx`, `LearningAssignmentController.php` |
| Command Center | sweeps + hand-read of `cm-command-center.tsx`, `CompetencyController.php` · **F-2 now a candidate** |
| Framework & Role Mapping | sweeps + hand-read of `RoleMappingController.php`, `StudioController.php` |
| Assessments | sweeps + hand-read of `AssessmentController.php`, `AssessmentCycleController.php` |
| Employee Profiles | sweeps + hand-read of `EmployeeCompetencyProfileController.php` |
| Certifications | sweeps + hand-read of `CertificationController.php` + both migrations |
| Skill Taxonomy / Ontology | **sweeps only** + `cm-taxonomy-ontology.tsx` skim |
| Organization Profile / Dept Mgmt | sweeps + guard results; `DepartmentManagementController` **not hand-read** |
| Employee Directory | sweeps + hand-read of `employee-directory.tsx`, `employee-directory-sheets.tsx` |
| Role & Permissions | sweeps + rights-matrix DB queries; controllers **not hand-read** |
| Compliance / Disciplinary | **sweeps only** |
| LMS × 3 | sweeps + hand-read of `course-builder-panel.tsx`, `LmsLearningController`, the certificate chain |
| Task × 2 | sweeps + hand-read of `MyTasksController.php`, `taskController.php` + DB |
| Talent × 7 | sweeps + guard records + hand-read of `PerformanceGoalController`, `PerformanceOverviewController`; the four `talent_*` controllers **not hand-read** |
| HRIT | sweeps + guard records; `HrmsController` **not hand-read — largest unread controller** |
| Agentic | sweeps + guard records; **not hand-read** |
| Reports | decision-level only |
| CRM | **not audited — out of scope** |

**Honest summary: 12 of 30 hand-read at file level, 18 covered by sweeps, guard
records and DB queries.** That is what the C18 trade bought.

---

# EXIT CRITERIA

| | |
|---|---|
| V4 error rate | **3.3%** — **under 5%** |
| V7 | **CLEAN** — no dropped work |

## ✅ GATE C STANDS.

One correction applied: **competency.md F-2 downgraded to a candidate.** V6's four
number corrections are already propagated.

**The audit is closed. Nothing further is verified. The remaining risk is no longer
that something was measured wrong — it is that nothing has been built.**
