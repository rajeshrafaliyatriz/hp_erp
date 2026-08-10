# Employee's 31 qualified grants — the work, grouped before reading

**Rule:** deny wherever *scoped-to-caller* is **no** OR *capability exists* is
**no**. Read once per controller, apply to every screen it serves.

---

## RESOLVED — 1 of 31

### Payroll (all 7 screens) · `V (own payslip)` · **DENY ALL SEVEN**

| Screen | Scoped to caller? | Capability exists? | Verdict |
|---|---|---|---|
| 105 Payroll Type | n/a — configuration | — | **DENY** |
| 106 Salary Structure | **NO** — `PayrollController::employeeSalaryStructure` reads `$request->employee_id` | — | **DENY** |
| 107 Rollover Salary Structure | disabled (`status=0`) | — | **DENY** |
| 108 Payroll Deduction | n/a — configuration | — | **DENY** |
| 109 Form 16 | **NO** — `PayrollController::form16` | — | **DENY** |
| 110 Salary Certificate | **NO** — `hrmsSalaryCertificateIndex` reads `$request->input('employee_id')` and lists **all** departments | — | **DENY** |
| 140 Monthly Payroll Report | n/a — org-wide report | — | **DENY** |

> **There is no payslip screen among the seven.** `V (own payslip)` grants access
> to a capability **that does not exist**.

**Also logged from the same read (per the one-read rule):** four payroll screens
take `employee_id` **from the request** — **G-SEC-12's sibling in READ form**.
Already inside the C23 candidate set; recorded here so the evidence is not lost.

---

## THE GROUPING — 30 remaining screens, ~11 controller families

**Not thirty reads.** One read per family, verdict applied to every screen it
serves.

| # | Family | Screens (menu ids) | Qualifier pattern |
|---:|---|---|---|
| 1 | **Competency** — `RoleMappingController`, `AssessmentController`, `EmployeeCompetencyProfileController`, `DevelopmentPlanController`, `CertificationController` | 154, 155, 156, 157, 158 | *own role · self-assessment · self · own* |
| 2 | **Task** — `MyTasksController`, `TaskController`, `ProjectController`, `DependencyController` | 210, 211, 212, 213, 214, 215 | *self · own · member* |
| 3 | **LMS** — `LmsLearningController`, `LmsCourseEnrollController` | 80, 81, 83, 209 | *self · own* |
| 4 | **Talent** — `talent_*`, Onboarding, Mobility, Offboarding controllers | 47, 48, 49, 52, 171 | *referrals · own checklist · self-appraisal · internal jobs · own exit* |
| 5 | **HRIT Attendance** | 100, 101 | *own punch · self* |
| 6 | **HRIT Leave** — `ApplyLeaveController`, `LeaveRequestApiController` | 102, 103, 104 | *own · self* |
| 7 | **Employee Directory** | 22 | ***field-level** — basic fields only* |
| 8 | **Skill Gap Analysis** | 26 | *self* — ⚠️ **menu 26 is `status=0` and has no component (G-A-04)** |
| 9 | **Consolidated Reports** | 122 | *self* |
| 10 | **The three reports** | — | **already denied — not built** |

### Two called out before the reads

- **#7 Employee Directory** is the only **field-level** qualifier in Employee's set. It cannot be expressed by a menu boolean **even if the controller scopes perfectly** — it belongs to §3.8 regardless of what the read finds.
- **#8 Skill Gap Analysis** is marked (SHIP) but the menu is disabled with no component behind it. **Likely the same class as Payroll: a qualifier on a capability that does not exist.** Confirm on the read.

### Expected outcome, stated as a prediction so it can be wrong

Given the Payroll result, I expect **several "own X" screens also take their
subject from the request** and land on DENY. **That is a prediction, not a
finding**, and each needs its file:line.

---

## Status

**1 of 31 resolved. 30 remaining across ~11 controller families.**
**4b does not apply until all 31 resolve and the seed regenerates.**
