import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"
p = os.path.join(D, "12-gate-c-verification.md")
t = io.open(p, encoding="utf-8").read()

t = t.replace("**Status:** `V1 and V6 complete. V2\u2013V5, V7, V8 in progress.`",
              "**Status:** `COMPLETE. Trimmed to V4 + V7 by instruction; V2/V5 sampled within V4; V3 and V8 answered inline.`")
t = t.replace("*V2\u2013V5, V7 and V8 follow in the next pass. Exit criteria are assessed once V4's\nsample error rate exists.*", "")

t += """

---

# V4 — 30-CLAIM SAMPLE, RE-DERIVED FROM SCRATCH

Write-ups **not re-read first**. Weighted to NEGATIVE and NUMERIC, as instructed.
V2 (negative claims) and V5 (cross-document contradiction) are **sampled within
this 30**, not run separately.

## Result

| Class | Sampled | Errors | Rate |
|---|---:|---:|---:|
| NUMERIC | 9 | 0 | 0% |
| NEGATIVE \u2014 schema | 6 | 0 | 0% |
| NEGATIVE \u2014 code / frontend | 9 | **1** | 11% |
| STRUCTURAL | 4 | 0 | 0% |
| BEHAVIOURAL | 2 | 0 | 0% |
| **TOTAL** | **30** | **1** | **3.3%** |

## What re-derived cleanly

**All nine numeric claims, exactly:** `s_user_skill_jobrole` 79,295 \u00b7
`s_jobrole_skills` 62,208 \u00b7 `s_jobrole_task` 55,961 \u00b7 `s_users_skills` 3,976 \u00b7
enrolments 1,426 \u00b7 content-progress **1** \u00b7 certificates **0** \u00b7
`compliance_relevance` 804 \u00b7 rights rows 4,879 with **`can_view`=1 on all 4,879 and
`can_add`/`can_edit`/`can_delete`=1 on zero**.

**All six schema negatives:** `s_skill_matrix` has no `sub_institute_id` \u00b7
`s_user_jobrole_task` has no skill/competency column \u00b7 no `certification_type`
table \u00b7 no `task_status_history` \u00b7 `s_jobrole` and `master_skills` have no tenant
column.

**Structural / behavioural:** `app/Events`, `app/Listeners`, `app/Observers` all
absent \u00b7 `s_user_skill_jobrole` has exactly **two** insert sites
(`RoleMappingController:224`, `SchoolSetupController:408`) \u00b7 `LibraryController`
accepts `department_id` \u00b7 `JobroleApiController` joins it to `hrms_departments` \u00b7
`authMiddleware` accepts session **or** token \u00b7 `contentLibraryControllerOld` is a
live writer of `content_master` \u00b7 `MyTasksController` validates `delay_category`
against a closed enum \u00b7 `AssignLearningForm` has no competency reference \u00b7 the
learning kebab offers only *Mark \u2026* and *Remove Assignment* \u00b7 **no import control
on any library tab** \u00b7 `QuickCreateKind` has no `role-mapping`.

> \u26a0\ufe0f **One of these nearly scored wrong.** "No import control" first returned
> **22 hits** for `import` in `library-tab.tsx` \u2014 all ES module statements. Re-run
> against upload/file controls: **none**. The claim holds, but a careless pass
> would have called a true claim false. (R4)

## The one error \u2014 competency.md F-2, Command Center navigation

| | |
|---|---|
| **Claimed** | *"`CONTENT_MAP_LOADERS` is keyed `'1','2','3','4','5','204','186'`"*, cited in `cm-command-center.tsx` |
| **Re-derived** | It lives in **`hooks/use-content-map.ts`**, not `cm-command-center.tsx`, and has **eight** keys: `1 2 3 4 5 204 186 50` \u2014 **`50` was missing from my list** |

**Two errors in one claim: wrong file, incomplete list.**

**What it changes:** the *substance* \u2014 that Command Center tiles navigate to
`/module/competency-management/{submenuId}/{submenuId}` (confirmed at
`cm-command-center.tsx:371`) and land on placeholders \u2014 is **not re-derived
either way**, because I did not enumerate the tile submenuIds. **F-2 is downgraded
from a finding to a CANDIDATE (R6)** until someone does.

**Direction: this error OVERSTATED the finding** (a shorter key list makes more
navigation look broken). That is now **four of five** recent errors overstating \u2014
consistent with V6, not with R11's earlier 13\u20130 under-reporting.

---

# V7 — RECONCILIATION AUDIT · **CLEAN**

Every **ALREADY-APPROVED** verdict across the six write-ups was checked against the
item it points at. **A wrong one silently drops work from Gate D \u2014 the only error
class that damages the build.**

| Verdict points at | Specified in `02-domain-model.md`? |
|---|---|
| `course_competency_map` | \u2705 9 references |
| `jobrole_competency_map` | \u2705 8 |
| `competency_kasba_item` | \u2705 11 |
| `jobrole_task_competency_map` | \u2705 5 |
| `certification_competency_map` | \u2705 2 (\u00a710.1, steps 3b/9b) |
| `portal_identity` | \u2705 4 |
| `reporting_manager_id` | \u2705 5 |
| Block-don't-cascade delete (L-06) | \u2705 \u00a711 (iii) |
| Import flow (L-10) | \u2705 \u00a79, steps 8\u20139 |
| Text\u2192FK (L-11) | \u2705 \u00a710 steps 12\u201314 |

**No already-approved verdict was found pointing at something that does not cover
the connection claimed. No work is silently dropped.**

---

# V3 — WAS THE C35 CHECKLIST APPLIED? \u00b7 three lines

**Applied in all six.** Competency 6 forms / 18 files \u00b7 Organization 4 \u00b7 LMS 5 \u00b7
Task 4 \u00b7 Talent 6 \u00b7 Other 0 forms *(CRM out of scope, Reports has no forms, HRIT's
forms belong to owning modules)*.

**Both directions were checked**, and the inverse case (a column accepted but never
sent) was found **only in Competency** \u2014 which is itself a result: `L-01` is one
screen's defect, not a systemic pattern.

**The S-5 class (divergent vocabularies writing one table) has NO replacement
mechanism.** S-5 was retired without one, so that defect class is **currently
unchecked outside the one instance already documented** (Command Center vs Library
approve_status vocabularies). **Recorded as a known gap in coverage, not a finding.**

---

# V8 — COVERAGE, one line per sub-module

| Sub-module | How it was covered |
|---|---|
| Library & Taxonomy | **full write-up + calibration** \u2014 176 rows, 0 structural errors; `library-config.ts`, `library-tab.tsx`, `library-form.tsx`, `LibraryController.php` hand-read |
| Competency Library | **full write-up** \u2014 `cm-competency-library.tsx`, `skillLibraryController.php` hand-read |
| Development & Career Path | **calibrated (C1b, 0 of 206)** + hand-read of `cm-development-career.tsx`, `LearningAssignmentController.php` |
| Command Center | sweeps + hand-read of `cm-command-center.tsx`, `CompetencyController.php` \u00b7 **F-2 now a candidate** |
| Framework & Role Mapping | sweeps + hand-read of `RoleMappingController.php`, `StudioController.php` |
| Assessments | sweeps + hand-read of `AssessmentController.php`, `AssessmentCycleController.php` |
| Employee Profiles | sweeps + hand-read of `EmployeeCompetencyProfileController.php` |
| Certifications | sweeps + hand-read of `CertificationController.php` + both migrations |
| Skill Taxonomy / Ontology | **sweeps only** + `cm-taxonomy-ontology.tsx` skim |
| Organization Profile / Dept Mgmt | sweeps + guard results; `DepartmentManagementController` **not hand-read** |
| Employee Directory | sweeps + hand-read of `employee-directory.tsx`, `employee-directory-sheets.tsx` |
| Role & Permissions | sweeps + rights-matrix DB queries; controllers **not hand-read** |
| Compliance / Disciplinary | **sweeps only** |
| LMS \u00d7 3 | sweeps + hand-read of `course-builder-panel.tsx`, `LmsLearningController`, the certificate chain |
| Task \u00d7 2 | sweeps + hand-read of `MyTasksController.php`, `taskController.php` + DB |
| Talent \u00d7 7 | sweeps + guard records + hand-read of `PerformanceGoalController`, `PerformanceOverviewController`; the four `talent_*` controllers **not hand-read** |
| HRIT | sweeps + guard records; `HrmsController` **not hand-read \u2014 largest unread controller** |
| Agentic | sweeps + guard records; **not hand-read** |
| Reports | decision-level only |
| CRM | **not audited \u2014 out of scope** |

**Honest summary: 12 of 30 hand-read at file level, 18 covered by sweeps, guard
records and DB queries.** That is what the C18 trade bought.

---

# EXIT CRITERIA

| | |
|---|---|
| V4 error rate | **3.3%** \u2014 **under 5%** |
| V7 | **CLEAN** \u2014 no dropped work |

## \u2705 GATE C STANDS.

One correction applied: **competency.md F-2 downgraded to a candidate.** V6's four
number corrections are already propagated.

**The audit is closed. Nothing further is verified. The remaining risk is no longer
that something was measured wrong \u2014 it is that nothing has been built.**
"""
io.open(p, "w", encoding="utf-8").write(t)
print("V4/V7/V3/V8 written")
