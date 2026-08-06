# User flow — Employee

**Gate B deliverable.** Read-only analysis; no application code changed.
Date: 2026-08-05

**Role:** Employee · **Scope:** Self · **Population:** 238 of 386 users (62%) — the
largest group in the product, and the one whose experience decides whether the
platform feels like one system or six.

Each journey states **today** (verified against source), **target**, and the
**gaps** with IDs. New gaps are appended to `07-gap-register.md` as one-liners.

---

## 0. What an Employee can reach today

The sidebar **is** filtered server-side by `can_view`
(`tblmenumasterG2gController::displaySidebarMenu`). But the underlying data grants
everything to everyone:

| Profile | Menus viewable (all tenants) |
|---|---:|
| **Employee** | **1,657** |
| HR | 1,650 |
| Admin | 1,500 |

So today an Employee's navigation is **wider than an Administrator's**, and the API
behind it has no role check at all on 279 write routes. → `G-SEC-07`, `G-SEC-01`

**Everything below therefore describes a target state.** There is currently no
meaningful difference between what an Employee, an HR user and an Admin can reach.

---

## 1. Landing — "what do I need to do today?"

### Today
`/dashboard` → `MainDashboard` (the only `m0` entry). The nav offers ~1,657 menu
rows, most of which are irrelevant to an employee and many of which are
administrative.

### Target
A single self-scoped landing answering four questions, each deep-linking into the
owning module:

| Tile | Source of truth | Owning module |
|---|---|---|
| **My tasks due / overdue** | `/api/task-management/my-tasks` | Task |
| **My learning due** | `lms_course_enroll` where status ≠ completed | LMS |
| **My capability gaps** | `s_skill_matrix` vs required proficiency | Competency |
| **Actions needing me** | self-appraisal open, assessment due, document requested | Talent / Competency |

### Gaps
- `G-FLOW-01` — no self-scoped landing page exists; `MainDashboard` is not role-aware.
- `G-SEC-07` — nav breadth is inverted; an Employee sees more than an Admin.

---

## 2. **My capability profile** — the chain that does not exist

This is the brief's stated biggest confusion (`G-DATA-02`) and the spine of the
product. Specified here end to end.

### The intended chain

```
Employee                    tbluser.id
   ↓ has a job role
JobRole                     tbluser.allocated_standards → s_user_jobrole.id
   ↓ the role requires competencies at proficiency levels
Required capability         jobrole_competency_map        ← DOES NOT EXIST (Q-C1)
   ↓ each competency is a bundle of KASBA items
Competency definition       competency + competency_kasba_item ← DOES NOT EXIST (Q-C2)
   ↓ the employee has a measured level per item
Current proficiency         s_skill_matrix (user_id, skill_id, type, skill_level)
   ↓ difference
GAP                         required − current            ← COMPUTED NOWHERE
   ↓ a gap should produce a plan
Development plan            s_competency_development_plans
   ↓ a plan should assign learning
Course assignment           course_competency_map         ← DOES NOT EXIST (Q-B4)
```

### Link-by-link status

| # | Link | Exists? | Evidence |
|---:|---|---|---|
| 1 | Employee → JobRole | **Yes** | `tbluser.allocated_standards` → `s_user_jobrole` (4,610 rows) |
| 2 | JobRole → required competencies | **No** | `s_jobrole_skills` has 62,208 rows but is **global reference data, string-keyed, no `sub_institute_id`** — an industry library, not a tenant's definition. Q-C1 adds `jobrole_competency_map` |
| 3 | Competency → KASBA items | **No** | No competency-as-bundle table. `s_skill_matrix.type` already enumerates the five dimensions, so the grain is right; the bundle is missing. Q-C2 |
| 4 | Employee → current proficiency | **Yes, thinly** | `s_skill_matrix`, **169 rows for 386 users** — most employees have no measured capability at all |
| 5 | Gap = required − current | **No** | Nothing computes it. "Skill Gap Analysis" exists as menu id 26, **disabled**, with no component |
| 6 | Gap → development plan | **No** | Plans exist and can be created manually; nothing derives one from a gap |
| 7 | Plan → course assignment | **No** | `sub_std_map` has `jobrole` as **longtext holding a role name** (73 of 96 rows) and no skill FK at all. Q-B4 |

**Four of seven links are missing, and all four are join tables approved in Q-B4 /
Q-C1 / Q-C2 / Q-C3.** This is why they must land as one coherent schema change
(→ `02-domain-model.md`).

### What the Employee sees, today vs target

| | Today | Target |
|---|---|---|
| Screen | Competency → Employee Profiles (`cm-employee-profiles.tsx`) | Same screen, self-scoped |
| Which employee | `CmEmployeeProfiles({ userId })` falls back to the logged-in user, and the router passes no props — so **always themselves** | Explicitly self; a manager reaches a report's profile from their own flow |
| Ratings shown | `s_skill_matrix` rows for that user | Same, plus **required level** beside each |
| Gap | **Not shown** | Required vs current, per KASBA dimension |
| Evidence | **Table missing** (`competency_evidence`) → `G-STR-02` | Certificates, assessments, task evidence |
| Next step | none | "Close this gap" → the assigned course |

### Gaps
- `G-DATA-02` — the chain does not exist (registered).
- `G-FLOW-02` — Employee Profiles shows ratings with no *required* level, so a gap is not visible even where data exists.
- `G-FLOW-03` — 169 rating rows for 386 users: most employees would see an empty profile. Import/seeding is part of Q-C1's import flow.

---

## 3. My learning

### Today
LMS → My Learning (menu 209) → `lms/delivery/learning-delivery-workspace.tsx`.
Enrolments come from `lms_course_enroll` (1,426 rows). Completion is real:
`status='completed'`, `completed_at` (`LmsLearningController:365`). Certificates
issue into `lms_certificates` (**0 rows today**).

### Target
Same screens, plus **context**: why a course was assigned, which competency it
builds, and what completing it changes.

| Element | Today | Target |
|---|---|---|
| Course list | Title, progress | + the competency it builds, + why assigned |
| "Why am I doing this?" | absent | "Assigned by <manager> to close <competency> gap" or "Mandatory: <compliance rule>" |
| On completion | `status='completed'` | Per **Q-B2**: if the competency is critical/regulated → unlock an assessment; else raise proficiency directly. **Tenant setting, per-competency override** |
| Certificate | issued to `lms_certificates` | also visible in Competency → Certifications as evidence |

### Gaps
- `G-DATA-01` — no course↔competency link, so none of the above context can be shown.
- `G-FLOW-04` — assignment carries no reason; the employee cannot tell mandatory from optional or see who assigned it.
- `G-FLOW-05` — `lms_certificates` is empty despite 1,426 enrolments; certificate issuance appears never to have run.

---

## 4. My tasks

### Today
Task → My Tasks (menu 211) → `my-tasks-view.tsx`, backed by
`/api/task-management/my-tasks` (`MyTasksController`). Status updates go through
`PATCH /my-tasks/{id}/status`, gated by `task.permission:task.status` — **the only
module with real server-side role enforcement.**

Task creation (`create-task-modal.tsx:505`) already offers **"From job role"** vs
**"Custom task"**, and can promote a custom task into the Job Role Task library via
`competencyLibrariesService` — a working Task→Competency link.

### Target
Add the capability dimension (golden thread 2), from the employee's side only:

| Element | Today | Target |
|---|---|---|
| Task detail | title, status, due, comments | + the competency this job-role task exercises |
| On overdue / rejected / reopened | status changes | + an **evidence record** against the linked KASBA (`Q-B3`) |
| What the employee sees | nothing | the recommended remediation course, **shown immediately** per Q-B3 |
| Rating impact | none | **none without explicit manager confirmation** — Q-B3 is emphatic |

The employee-facing half of golden thread 2 is deliberately gentle: they see a
suggestion, never a downgrade.

### Gaps
- `G-STR-01` / Q-C3 — `s_user_jobrole_task` (85,663 rows) keys `jobrole` and `task` as **text**, with no competency FK, so "which capability does this task exercise" is unanswerable.
- `G-FLOW-06` — task detail shows no capability context at all.

---

## 5. Self-assessment

### Today
Competency → Assessments (`cm-assessment-workspace.tsx`). `EmployeeCompetencyProfileController::addSkill/updateSkill`
are the only writers to `s_skill_matrix`; both are **admin-shaped** endpoints and
now sit behind `profile:admin,hr,manager`.

LMS also has "Self skill rating" (menu 88) — **hidden behind a disabled parent**,
and on the DELETE list pending the Gate C harvest check.

### Target
An employee submits a **self-rating** which is an *input to* an assessment, never a
direct write to their own proficiency.

```
Employee self-rating  →  assessment record (pending)
Manager / assessor rating → assessment record
                            ↓ on completion
                     proficiency updated in s_skill_matrix
```

### Gaps
- `G-FLOW-07` — there is no employee-facing self-rating path today: the only writers to `s_skill_matrix` are admin endpoints. An employee cannot record their own view of their capability.
- `G-FLOW-08` — self-rating must not write proficiency directly, or the measurement is worthless. The write path must go through an assessment record.

---

## 6. My development plan

### Today
Competency → Development & Career Paths (`cm-development-career.tsx`, 2,604 lines —
the largest competency screen). Plans exist and are manually created.

### Target
| Trigger | Effect |
|---|---|
| Gap detected (§2) | plan proposed, awaiting manager approval |
| 3 task failures / 90 days (Q-B3) | remediation added to the plan |
| Assessment result below required | plan item added |
| Manager assigns | plan item added |

The employee sees: what to close, by when, what learning is attached, progress.
**Approval is the manager's** — `03-rbac-matrix.md` gives Employee `V C (own)` and
Reporting Manager `A`.

### Gaps
- `G-FLOW-09` — no input produces a plan automatically; every plan is hand-made, so nothing in §2–§5 flows into it.

---

## 7. My certifications

### Today
Competency → Certifications (`cm-certifications.tsx`, 2,008 lines). **Its backing
table `competency_certification_requirements` does not exist** → `G-STR-02`. This is
why the module connects to nothing.

Separately, LMS → Certifications & Records reads `lms_certificates` (0 rows).

### Target
One certification record per employee, sourced from either LMS completion or an
external upload, expiring on schedule, and **gating tasks that require it**
(golden thread 8).

### Gaps
- `G-STR-02` — required table missing.
- `G-DATA-03` / D5 — two stores of one concept (LMS vs Competency).
- `G-FLOW-10` — no expiry notification path (blocked on the notification service, `05-data-flow-contracts.md`).

---

## 8. Self-service — leave, attendance, payslip

The most complete employee journeys in the product today.

| Journey | Today | Gap |
|---|---|---|
| Punch in/out | `/api/attendance/punch-in`, `/punch-out`; My Attendance live | — |
| Apply for leave | Leave Requests live; approval currently has **no manager to route to** | `G-STR-03` |
| View payslip | Payroll screens live | Field-level rules needed (`G-SEC-03` §3.8.4) |

Leave approval is the clearest case of `G-STR-03`: the workflow exists, the role
vocabulary exists in `hrms_leave_role_permissions` ("Reporting Manager", scope
"Team"), and **no user can hold that role** because there is no reporting line.

---

## 9. Onboarding (as a new hire)

### Today
Talent → Onboarding (menu 48) is live and genuinely API-backed
(`onboarding-center.tsx` — verified, not mock).

### Target — golden thread 1
```
Employee created (Organization)
   → job role assigned
   → framework resolves required KASBA          ← needs Q-C1/Q-C2
   → onboarding tasks generated
   → gap analysis runs                          ← needs §2
   → LMS courses auto-assigned                  ← needs Q-B4
   → baseline assessment scheduled
```
Steps 1–2 and 4 work. Steps 3, 5, 6, 7 depend on the missing join tables.

### Gaps
- `G-FLOW-11` — a new hire's role change triggers nothing downstream; there is no event mechanism (`G-STR-04`).

---

## 10. Offboarding (as a leaver)

### Today
Talent → Offboarding live; `talent_offboarding_cases` has its own `manager_id`.

### Target — golden thread 9
Exit → open tasks reassigned → learning/assessments closed → **access revoked per
RBAC** → records archived and auditable.

### Gaps
- `G-FLOW-12` — no automatic access revocation on termination. `tbluser.terminated_date` exists but nothing acts on it.

---

## 11. Dead ends an Employee hits today

| Dead end | Cause |
|---|---|
| Nav shows administrative screens they cannot use | `G-SEC-07` — everyone can view everything |
| Employee Profiles shows ratings with no required level | `G-FLOW-02` |
| Certifications screen with no backing table | `G-STR-02` |
| A course with no stated purpose | `G-DATA-01` |
| Leave request with no approver | `G-STR-03` |
| No way to record a self-rating | `G-FLOW-07` |

---

## 12. What an Employee must never see

From `03-rbac-matrix.md` §3.8, restated as flow constraints:

- Another employee's salary, personal contact, identity documents or bank details.
- Another employee's competency ratings or gaps.
- **Manager private comments** on their own review (`–`, not merely hidden).
- The identity of 360-degree feedback authors.
- A performance rating **before it is released** — requires a `released_at` gate.
- Any audit log.

Each must be enforced by the API resource layer, not the UI.

---

## 13. Gap summary

| ID | One-line | Severity |
|---|---|---|
| `G-FLOW-01` | No self-scoped landing page; `MainDashboard` is not role-aware | S2 |
| `G-FLOW-02` | Employee Profiles shows current level with no required level, so no gap is visible | S2 |
| `G-FLOW-03` | 169 rating rows for 386 users — most profiles would be empty | S2 |
| `G-FLOW-04` | Course assignment carries no reason; mandatory vs optional indistinguishable | S3 |
| `G-FLOW-05` | `lms_certificates` empty despite 1,426 enrolments — issuance appears never to have run | S2 |
| `G-FLOW-06` | Task detail shows no capability context | S3 |
| `G-FLOW-07` | No employee-facing self-rating path exists | S2 |
| `G-FLOW-08` | Self-rating must route through an assessment, never write proficiency directly | S2 (design) |
| `G-FLOW-09` | No input produces a development plan automatically | S2 |
| `G-FLOW-10` | No certification expiry notification path | S2 |
| `G-FLOW-11` | Role assignment triggers nothing downstream | S1 (part of `G-STR-04`) |
| `G-FLOW-12` | No automatic access revocation on termination | S1 |

Pre-existing gaps this flow depends on: `G-STR-01` (join tables), `G-STR-02`
(missing tables), `G-STR-03` (reporting line), `G-STR-04` (no events),
`G-DATA-01` (course↔competency), `G-DATA-02` (the chain), `G-SEC-01`, `G-SEC-03`,
`G-SEC-07`.

---

## 14. Verification status

| Claim | Method | Status |
|---|---|---|
| `can_view`=1 on all 4,879 rows; add/edit/delete=0 | Live count per column | **Verified** |
| Employee 1,657 menus vs Admin 1,500 | Live join, all tenants | **Verified** |
| Sidebar filtered server-side by `can_view` | `tblmenumasterG2gController` | **Verified** |
| `s_skill_matrix` = 169 rows | Live count | **Verified** |
| `lms_course_enroll` = 1,426; `lms_certificates` = 0 | Live count | **Verified** |
| `s_user_jobrole_task` text-keyed, no competency FK | Column list | **Verified** |
| `sub_std_map.jobrole` longtext, 73/96 populated, no skill FK | Column type + count | **Verified** |
| Employee Profiles always renders the logged-in user | `cm-employee-profiles.tsx:54,70` + router passes no props | **Verified** |
| Custom vs job-role task choice exists | `create-task-modal.tsx:505` | **Verified** |
| `my-tasks` status gated by `task.permission` | `routes/api.php:941` | **Verified** |
| Onboarding is API-backed, not mock | Full read of `onboarding-center.tsx` | **Verified** |
