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

## THE VERDICTS — ALL FAMILIES READ

**Reported LMS and Task first, as directed.**

### GRANT — 2 families, 10 screens. Genuinely and correctly self-scoped

| Screens | Evidence | Verdict |
|---|---|---|
| **210–215 Task** (My Tasks, Task, Project, Dependency) | `MyTasksController.php:136,180,301,319` filters on `$context['user_id']`, and `ResolvesTaskContext.php:40` derives that id from `PersonalAccessToken::findToken($token)->tokenable` — **the token, not the request** | **GRANT** |
| **80, 81, 83, 209 LMS** (Learning Dashboard, Assignments, Certifications & Records, My Learning) | All 15 subject reads go through `requireUser()` → `contextUserId()` → `ResolvesLmsIdentity::lmsIdentity()` → `resolveApiIdentity($request)` — **token-derived** (`LmsLearningController.php:53-56`, `ResolvesLmsIdentity.php:138-142`) | **GRANT** |

**The prediction was wrong here, and that is the good outcome.** I predicted several
"own X" screens would take their subject from the request. **The two families used
constantly by everyone are the two that are correct** — because G-SEC-12's fix
already landed on them. `ResolvesLmsIdentity`'s own header names the exact bug that
was closed: *"my courses, my sessions, my deadlines returned whoever the caller
named instead of the caller."*

### DENY — 5 families, 12 screens. Controller does not scope to the caller

| Screens | Evidence (file:line) | Why |
|---|---|---|
| **154–158 Competency** | `EmployeeCompetencyProfileController.php:15` `show(Request $request, $id)` then `:26,95,159` filter on **`$id`, a route parameter**. `competencyContext()` authenticates and gives the tenant but **`$id` is never compared to `$context['user_id']`** | Any employee reads any colleague's full competency profile — skills, ratings, assessor, manager. **`addSkill():238` and `updateSkill():318` take the same unchecked `$id`** — so it is **write**, not just read |
| **100, 101 Attendance** | `AttendanceTrackingApiController.php:223,283` — punch-in and punch-out both resolve the subject as **`$request->input('employee')`** | An employee can **punch in and out as a colleague**. Attendance fraud, by query parameter |
| **102, 103, 104 Leave** | `LeaveRequestApiController.php:174` — `$userId = (int) ($request->input('employee_id') ?: $context['user_id']);` — and `LeaveOptionsController.php:103`, same shape | **Request-first with the caller as fallback.** Reads as safe, is not: passing `employee_id` returns a colleague's leave |
| **47, 48, 49, 52, 171 Talent** | **Zero caller-scoped queries across all 11 Talent controllers** — `grep '$context['user_id']' … | where` returns nothing | Nothing enforces *referrals · own checklist · self-appraisal · own exit* |
| **122 Consolidated Reports** | Menu 122 is **Organization Management Report** — org-wide by definition | No self-scope to enforce |

### DENY — the two pre-flagged, both confirmed

| Screen | Verdict |
|---|---|
| **22 Employee Directory** | **DENY — decided as a permission, not pending a read.** "Basic fields only" is field-level and no menu boolean can express it. What a bare `V` delivers today is the full employee record, org-wide. See §3.8 note below |
| **26 Skill Gap Analysis** | **DENY — G-RBAC-02 confirmed a SECOND time.** `status=0`; **no component and no nav entry** in the Next.js app |

---

## TWO INSTANCES MAKE IT A PATTERN

**G-RBAC-02 is no longer an anecdote.** Payroll and Skill Gap Analysis are two
independent §3.x grants whose qualifier describes a capability **that was never
built** — in different modules, found by different evidence.

**This strengthens the §3.8 scoping argument considerably.** §3.1–3.7 cannot be
read as a specification of current behaviour. It is a specification of *intended*
behaviour, and every qualifier in it needs the same test before it becomes a grant.

---

## EMPLOYEE DIRECTORY — THE REASON, RECORDED

**A staff directory is something employees legitimately expect, and this removes
it.** That is the real cost and it is not being minimised.

It is denied anyway because **an org-wide directory returning full employee records
is exactly what a security review flags**, and *"employees cannot look up a
colleague's extension yet"* is **a support ticket, not a finding**.

> **Registered as one of the FIRST things §3.8 unlocks.**
> **The basic-fields view is a real product requirement, not merely a permission.**
> Field-level scoping is what makes the directory shippable — the permission
> layer alone can only choose between *everything* and *nothing*, and *nothing*
> is the only safe half of that choice.

---

## ONE THING CHECKED AND CLEARED

`OffboardingClearanceController.php:147` and `OnboardingDocumentController.php:186`
both write `X_by = X_by ?: $context['user_id']`, which pattern-matches the
request-first anti-pattern above. **It is not one.** `cleared_by` and `verified_by`
are **absent from the `$request->validate()` allow-list** (`:127-137`), so
`fill($validated)` cannot set them. The `?:` preserves the **original** signer
across a re-save, which is correct. **No finding.**

---

## A COUNT TO RECONCILE, NOT TO ASSERT

My hand-count of the grouping gives **29 grants across 28 live screens**, against
the **31** stated earlier. **I am not resolving that by hand.** The seed is
generated from §3.1–3.7 directly, so the authoritative count comes out of the
generator. **Flagged so it is checked at regeneration rather than carried forward
as a settled number** (R19).

---

## Status

**All families read. 10 screens GRANT, 20+ DENY.**
**Next: regenerate the seed, re-run the three gates.**
