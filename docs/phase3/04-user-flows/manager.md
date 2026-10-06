# User flow — Reporting Manager

## ⚠ CORRECTIONS — 2026-08-11

### ✔ THE CENTRAL CLAIM STANDS — **the manager role still does not exist**

*"No user can hold it, and there is no reporting line to hold."* Re-measured
2026-08-11:

    reporting_manager_id populated   8 of 401 platform-wide
    department_head / reporting_manager  DEFINED as profiles, but DELIBERATELY
      ABSENT from COMPETENCY_ELEVATED and LEAVE_ELEVATED

### 🆕 IT IS NOW MEASURED RATHER THAN ASSERTED

The `reporting_coverage` readiness gate measures exactly this claim:

    tenant 1  blocked 0.00%      tenant 3  blocked 6.56%      threshold 50%

**But the gate enforces nothing**, and the reason belongs in this document
because it is this role's whole situation: **a gate cannot enforce against
features that were deferred waiting for it.** The manager-dependent flows were
never switched on — precisely pending the coverage this gate measures — so there
is nothing for it to refuse.

That leaves two expressions of one decision: the gate, which moves with the data,
and the const arrays, which hardcode the absence. **A hardcoded absence cannot be
told from an oversight by anything except a comment** — which is what the gate was
built to replace. When coverage is real, those lists should ASK the gate rather
than repeat its conclusion.

---

**Gate B deliverable.** Read-only analysis; no application code changed.
Date: 2026-08-05

**Role:** Reporting Manager · **Scope:** Team · **Population today: zero.**

This role does not exist. No user can hold it, and there is no reporting line to
resolve "team" against (`G-STR-03`). Everything below is therefore a target state
whose first prerequisite is the schema in `03-rbac-matrix.md` §2.4.

Golden threads 2, 4 and 5 all route through this role. It is the single largest
unlock in Phase 3.

---

## 1. Requirement 1 — What counts as a failure

"3 failures in 90 days" (Q-B3) is unusable until *failure* is defined. Here is the
proposal, grounded in the live data.

### 1.1 The number that forces this to be precise

| Measure | Value |
|---|---:|
| Tasks total | 2,271 |
| **Overdue right now** (`task_date` < today, status ≠ COMPLETED) | **2,245** |
| Status `PENDING` | 2,087 |
| Status `COMPLETED` | 22 |
| `approve_status = 'rejected'` | 22 |

**99% of all tasks in this database are currently overdue.** If "overdue" counted as
a failure, every employee would cross the threshold on day one and the signal would
be worthless before it shipped.

> **M1 — this is evidence about THIS database, not about task management.**
> The task data was bulk-loaded (1,000 rows on 2026-04-02, 476 on 2026-03-03) and
> then not worked. In a tenant with real task hygiene, severe overdue **is** a
> meaningful capability signal. "Overdue is weak" is therefore **not** a
> product-wide truth and must not be baked in as one.
>
> The correct mechanism is a **tenant readiness gate**: F4 activates only when the
> tenant's own overdue rate is below a configured threshold. See
> `02-domain-model.md` §8. A tenant at 99% overdue has a data-hygiene problem, and
> the product should say so rather than silently generate 2,245 accusations.

### 1.2 What data exists to judge with

| Field | Populated | Usable? |
|---|---:|---|
| `task_date` (due) | 2,268 / 2,271 | **Yes** |
| `status`, `approve_status` | yes | **Yes** |
| `approve_remarks` | some | Yes, as evidence text |
| **`skill_id`** | **1,514 / 2,271 (67%)** | **Yes — the competency link partly exists already** |
| `required_skills` | 1,749 / 2,271 | Yes |
| `delay_category` | **0** | Field exists, never filled |
| `delay_reason` | 1 | Effectively empty |
| `estimated_hours` / `actual_hours` | **0 / 0** | No effort-based measure possible |
| `acceptance_criteria` | **0** | No objective quality bar recorded |

**Correction to an earlier statement of mine:** I previously wrote that no task →
competency link exists. That is true of `s_user_jobrole_task` (text-keyed), but
**`task.skill_id` is populated on 67% of task instances**. The link exists at the
instance level and is the natural carrier for golden thread 2 — `jobrole_task_competency_map`
(Q-C3) then covers the catalogue level, not the instance level.

### 1.3 Proposed failure taxonomy

Not all failures are equal, and some are not the employee's at all.

| # | Event | Counts? | Weight | Rationale |
|---:|---|:-:|:-:|---|
| F1 | **Deliverable rejected** (`approve_status='rejected'`) | **Yes** | **3** | A human judged the work inadequate. The strongest available capability signal, and the only one backed by an explicit decision |
| F2 | **Reopened after acceptance** | **Yes** | **3** | Passed review, then failed in use — stronger than a rejection |
| F3 | **Failed quality check** against `acceptance_criteria` | **Yes** | **2** | Objective, but **no data today** — 0 rows populated |
| F4 | **Overdue — severe** (> 2× the planned duration, or > 30 days) | **Yes** | **1** | Sustained non-delivery |
| F5 | **Overdue — moderate** (beyond due, within 2× duration) | **No** | 0 | Too common to mean anything: 99% of tasks qualify |
| F6 | **Overdue — minor** (< 24h) | **No** | 0 | Noise |
| F7 | Blocked by an **upstream dependency** | **No** | 0 | Not the assignee's failure. **Must count against the dependency owner instead** |
| F8 | Blocked awaiting **approval or information** | **No** | 0 | Waiting on someone else |
| F9 | Reassigned away before the due date | **No** | 0 | Never theirs |
| F10 | Cancelled / descoped | **No** | 0 | Not a performance event |
| F11 | Overdue while the assignee is **on approved leave** | **No** | 0 | Requires the Leave↔Task link; absent today |

**Threshold:** the manager flag fires at **cumulative weight ≥ 3 within the window**,
not at "3 events". One rejection (weight 3) flags immediately; three severe
overdues (1+1+1) also flag. Tenant-configurable per Q-B3.

**Scoped to one job-role task**, per Q-B3 — a pattern on *one* capability, not
general busyness.

### 1.4 Why weights rather than a count

A count treats a rejected deliverable and a late-by-a-day task as the same event.
Weights let the strong signal (a human said this was not good enough) fire on its
own, while weak signals must accumulate. It also means the system degrades
sensibly as data improves: when `acceptance_criteria` starts being populated, F3
begins contributing without any threshold change.

### 1.5 What must be true before this can ship

| Prerequisite | Status |
|---|---|
| `delay_category` populated at task close | **0 rows today** — without it F7/F8 cannot be distinguished from F4, and the system will blame people for other people's blockers |
| Dependency data reliable | `dependencies-view.tsx` project filter is hardcoded to two invented names (`G-NAV-*`) |
| Leave ↔ Task link | Does not exist — F11 unenforceable |
| Reopen event recorded | No status transition history found |

> **M2 — corrected.** An earlier version of this section said "ship F1 and F2
> first, they need no new data collection", while the same table listed
> *no status-transition history* as unmet and `G-FLOW-25` confirms **reopen is
> undetectable today**. Those statements contradicted each other.

**Corrected sequencing:**

| Order | Signal | Prerequisite | Why |
|---|---|---|---|
| **1st — alone** | **F1 rejection** | none | `approve_status='rejected'` is already recorded; 22 rows exist to test against |
| **2nd** | F2 reopen | **task status-transition history** | Undetectable without it. Built as part of the event mechanism (`G-STR-04`), **not** as a separate item — it *is* the task audit trail, it makes reopen detectable, and it is a natural first consumer of the event store |
| **3rd** | F4 severe overdue | `delay_category` populated **and** the tenant's overdue-rate readiness gate passing | Shipping this early means blaming employees for blocked work — exactly what Q-B3's "never auto-lower" rule exists to prevent |
| **4th** | F3 quality check | `acceptance_criteria` populated (0 rows today) | No objective bar exists to fail against |

F1 alone is a usable product: a rejected deliverable is the strongest capability
signal available and needs nothing new built.

---

## 2. Requirement 2 — The no-manager case

Even after the reporting line exists, `reporting_manager_id` will be NULL for new
joiners, the CEO, contractors, and wherever the import data is incomplete. Today it
is NULL for **all 386 users**.

**Principle: never silently drop. Every manager-dependent step has a named
fallback, and the fallback is recorded on the record so it is auditable.**

### 2.1 The escalation ladder

```
reporting_manager_id
   ↓ null?
department head (hrms_departments.head_user_id)
   ↓ null?
parent department's head        (walk hrms_departments.parent_id, depth-bounded)
   ↓ null?
any HR Manager in the tenant    (role-based, not a named person)
   ↓ none?
tenant Administrator
   ↓ none?  → BLOCK the action with an explicit message + raise a data-quality signal
```

The final state is a **visible block**, never a silent pass. A leave request that
appears approved because nobody was found is worse than one that says "no approver
is configured for you — contact HR."

### 2.2 Per-step behaviour

| Manager-dependent step | Fallback | If nobody resolves |
|---|---|---|
| **Leave approval** | ladder above | Request stays `pending`, flagged "no approver configured". **Never auto-approve** |
| **Development plan sign-off** | ladder | Plan stays `draft`; employee can still see and work it. Non-blocking |
| **Proficiency confirmation** (Q-B3) | **Department Head may confirm** (M4), then HR Manager. **A recorded reason is mandatory** for a confirmation made outside the direct reporting line | No change to proficiency. Evidence still recorded. Safe default |
| **Task escalation / approval** | project manager (`task_management_projects.manager_id`) → department head → HR | Task stays open, flagged. Never auto-approve |
| **Performance review** | department head → HR Manager | Cycle cannot launch for that employee; HR sees an exception list **before** launch |
| **Offboarding approval** | department head → HR Manager | Blocks; exit is too consequential for a silent path |
| **Course enrolment approval** | department head → HR | **The single permitted auto-approval** — see M5 below |
| **360 assessor assignment** | HR Manager assigns manually | Assessment launches without the manager input, marked incomplete |

### 2.3 Two rules that prevent the common failure modes

- **Never auto-approve on absence.** The temptation is to let things through so the
  queue does not stall. That converts a data gap into an unlogged approval, which
  is the worst outcome in an audit.

  **M5 — the one named exception.** Enrolment in a course that is both **optional
  and free** may auto-approve. It is:
  - **tenant-configurable**, off by default;
  - **still an approval record**, written with `actor = system` and
    `reason = 'auto: optional free course, no approver configured'`.

  An approval with no audit record is precisely the outcome the rule above exists
  to prevent, so "auto-approve" here means *the system approves and says so*, never
  *the check is skipped*. No other step may auto-approve.
- **Surface the gap as data, not as an error.** Every fallback fires a
  `people_without_manager` data-quality signal. Interestingly `hpbrain_signal_rules`
  already ships `departments_without_manager` — whoever built it had reached the
  same conclusion.

### 2.4 Before the reporting line is populated

`reporting_manager_id` is NULL for all 386 users on day one. Until it is populated,
**every** step above lands on the HR-Manager fallback — which is correct behaviour
but would put the entire tenant's approvals on 72 HR users.

**Recommendation:** treat populating the reporting line as part of the same import
flow as Q-C1's job-role library, and **do not enable manager-dependent flows for a
tenant until its reporting coverage passes a threshold** (suggest 80% of active
employees). Report coverage as a readiness metric on the tenant.

---

## 3. Landing — "what needs me today?"

### Target
| Tile | Source |
|---|---|
| Approvals waiting | leave, dev plans, enrolment requests, task sign-offs |
| Team capability flags | Q-B3 signals at/over threshold |
| Team learning overdue | `lms_course_enroll` for the team |
| Review cycle actions | open self-appraisals, ratings due |

### Gaps
- `G-FLOW-13` — no manager landing exists; there is no manager.
- `G-FLOW-14` — no unified approvals queue; approvals live in each module separately.

---

## 4. My team's capability

The manager-side view of the §2 chain in `employee.md`.

| Element | Target |
|---|---|
| Team roster | `reporting_manager_id` = me, depth per `team_scope_depth` (A5) |
| Per person | required vs current per competency, gap highlighted |
| Team heatmap | competency × person, gaps visible at a glance |
| Drill-down | into that person's Employee Profile, **team-scoped** |

### Gaps
- `G-STR-03` — no reporting line, so no roster.
- `G-FLOW-15` — no team-scoped view of any competency screen; every one is either self or whole-tenant.

---

## 5. Rating my reports (golden thread 4)

| Step | Target | Today |
|---|---|---|
| Assessment launched for the team | HR launches; manager receives their queue | `cm-assessment-workspace.tsx` exists; no team scoping |
| Manager rates each report | writes an assessment input, **not** proficiency directly | `addSkill`/`updateSkill` write proficiency directly, gated `profile:admin,hr,manager` — where `manager` currently matches nothing |
| Self + manager + assessor combine | assessment result | no combination logic found |
| Result updates proficiency | `s_skill_matrix` | manual only |
| Gap recalculated | — | nothing computes a gap |

### Gaps
- `G-FLOW-16` — no assessment → proficiency write path; the two admin endpoints bypass assessment entirely.
- `G-FLOW-17` — no multi-rater combination (self / manager / assessor).

---

## 6. Approving development plans (golden thread 4 → 5)

Employee proposes or the system derives from a gap → **manager approves** → plan
becomes active → learning assigned.

The `A` in `03-rbac-matrix.md` §3.2 is this step. It cannot be expressed today
because no role can approve.

### Gaps
- `G-FLOW-09` (from `employee.md`) — nothing derives a plan from a gap.
- `G-FLOW-18` — no approval state on development plans.

---

## 7. Acting on a capability signal (golden thread 2)

The manager end of Q-B3.

```
Task rejected / reopened          → evidence recorded immediately, weight applied
Cumulative weight ≥ 3 in 90 days  → MANAGER FLAG
Manager opens the flag            → sees: the tasks, the linked competency,
                                     current vs required, suggested remediation
Manager chooses:
   (a) confirm a proficiency change   → s_skill_matrix updated, audited
   (b) assign remediation only        → course assigned, no rating change
   (c) dismiss with a reason          → evidence retained, threshold reset
```

**(c) matters.** A manager who knows the failures were caused by a bad brief must
be able to say so, and that dismissal is itself evidence — it is how the system
learns that the signal was wrong.

### Gaps
- `G-STR-04` — no event mechanism to fire any of this.
- `G-FLOW-19` — no evidence store (`competency_evidence` missing, `G-STR-02`).
- `G-FLOW-20` — no flag/queue concept anywhere in the product.

---

## 8. Performance review (golden thread 5)

Target: the review **pulls** real data instead of asking the manager to retype it —
task completion and overdue stats, competency gap movement, course completions,
certifications.

### Gaps
- `G-FLOW-21` — reviews are manual entry; no module feeds them.
- `G-SEC-03` — manager private comments need field-level protection (§3.8.2), including a `released_at` gate so a reviewee cannot read a draft rating.

---

## 9. Team leave, attendance, capacity

| Journey | Today | Gap |
|---|---|---|
| Approve leave | workflow exists; role vocabulary exists in `hrms_leave_role_permissions` ("Reporting Manager", scope "Team") | **no user can hold that role** — `G-STR-03` |
| Team attendance | endpoints exist, tenant-scoped | no team scope |
| Capacity vs workload | `/task-management/assignment-capacity` exists | not surfaced to a manager |

The leave module is the sharpest illustration in the product: the design is
complete and correct, and it cannot function because the role it depends on cannot
be assigned.

---

## 10. Recruitment and mobility

| Journey | Target | Gap |
|---|---|---|
| Raise a requisition | required competencies pulled from the job-role framework | needs Q-C1 |
| Interview scorecard | generated from those competencies | `G-FLOW-22` — scorecards are free-form |
| Nominate for internal move | from the team roster | needs `G-STR-03` |
| Identify successors | readiness from Competency, not recomputed | `G-FLOW-23` |

---

## 11. Dead ends a Manager hits today

Every one, because the role does not exist. Concretely, a person doing a manager's
job today is an **Employee** who:

- sees 1,657 menus (more than an Admin), none team-scoped;
- cannot approve anything, anywhere;
- can nonetheless call 279 unguarded write endpoints directly (`G-SEC-01`);
- has no roster, no queue, no team view.

---

## 12. What a Manager must never see

Per `03-rbac-matrix.md` §3.8, restated as flow constraints:

- **Payroll, salary, CTC or bank details for anyone**, including their own reports.
  Deliberate and confirmed — compensation is a different trust boundary from
  performance.
- Personal contact details, identity documents, dependants of their reports.
- Date of birth beyond day/month.
- Any data for an employee **outside their team scope**, subject to
  `team_scope_depth` (A5).
- Audit logs.
- Another manager's private review comments.
- The identity of 360 feedback authors **about themselves**.

The last one is easy to miss: a manager is also a reviewee, and the anonymisation
that protects their reports must protect the people rating *them*.

---

## 13. Gap summary

| ID | One-line | Severity |
|---|---|---|
| `G-FLOW-13` | No manager landing page / no manager | S2 |
| `G-FLOW-14` | No unified approvals queue; approvals scattered per module | S2 |
| `G-FLOW-15` | No team-scoped view on any screen — self or whole-tenant only | S1 |
| `G-FLOW-16` | No assessment → proficiency write path; admin endpoints bypass assessment | S2 |
| `G-FLOW-17` | No multi-rater combination (self / manager / assessor) | S2 |
| `G-FLOW-18` | Development plans have no approval state | S2 |
| `G-FLOW-19` | No evidence store for capability signals | S1 |
| `G-FLOW-20` | No flag/queue concept anywhere | S2 |
| `G-FLOW-21` | Performance reviews are manual entry; no module feeds them | S2 |
| `G-FLOW-22` | Interview scorecards are free-form, not competency-derived | S3 |
| `G-FLOW-23` | Succession readiness recomputed rather than read from Competency | S2 |
| `G-FLOW-24` | `delay_category` populated on 0 of 2,271 tasks — blocked work is indistinguishable from employee failure | S1 (blocks F4) |
| `G-FLOW-25` | No status-transition history, so "reopened" (F2) cannot be detected | S2 |

---

## 14. Verification status

| Claim | Method | Status |
|---|---|---|
| 2,245 of 2,271 tasks overdue | Live query, `task_date` < today AND status ≠ COMPLETED | **Verified** |
| `approve_status`: 22 rejected, 6 approved, 2,243 null | Live group-by | **Verified** |
| `task.skill_id` populated 1,514 / 2,271 | Live count | **Verified** |
| `delay_category` populated 0; `acceptance_criteria` 0; `estimated_hours` 0 | Live count per column | **Verified** |
| No `reporting_manager_id` on `tbluser` | `information_schema` scan | **Verified** |
| `hrms_leave_role_permissions` defines Reporting Manager / scope Team | Live query, 14 rows | **Verified** |
| `hpbrain_signal_rules` includes `departments_without_manager` | Live query | **Verified** |
| Leave workflow validates scope `Self,Team,Department,Organization` | `LeaveWorkflowApiController.php:136` | **Verified** |
