# 03 — Roles, scopes and the RBAC matrix

**Gate B deliverable 1 of 3.** Read-only analysis; no application code changed.
Date: 2026-08-05

Answers **Q-B1**: the proposed role model and scope rules, for your review before
any implementation.

---

## 1. What exists today

Four separate, unconnected mechanisms are doing parts of this job.

### 1.1 The profile table — 3 roles, and that is all

`tbluserprofilemaster`, verified live:

| Profile | Users | Present in |
|---|---:|---|
| Employee | 238 | every tenant |
| Admin | 76 | every tenant |
| HR | 72 | every tenant |
| `ZZ Audit Role v2` | 0 | tenant 3 only — a test artefact |

Every tenant is seeded with exactly Admin / HR / Employee. There is **no Manager,
Department Head, Instructor, Assessor, Recruiter, Executive or Auditor.**

### 1.2 A per-menu CRUD matrix — the right shape, carrying no information

> **CORRECTED 2026-08-05.** An earlier version of this section said the matrix was
> "genuine and fully populated". That was wrong in a way that matters: it is
> populated *uniformly*, so it carries **zero** access-control information, and the
> enforcement design in §4.2 would have granted everyone everything. → `G-SEC-07`

`tblgroupwise_rights_g2g` — **4,879 rows**:

```
menu_id, profile_id, can_view, can_add, can_edit, can_delete, dashboard_right, is_mobile
```

The shape is right. The data is not:

| Column | Rows set to 1 |
|---|---|
| `can_view` | **4,879 of 4,879 — every row** |
| `can_add` | **0** |
| `can_edit` | **0** |
| `can_delete` | **0** |
| `dashboard_right` | **0** |

**Everyone may view everything; nobody may edit anything.** Only `can_view` was
ever seeded (by `SeedG2gDefaultViewRights.php`, whose name says as much).

Worse, breadth is inverted — menu rows granted per profile, all tenants:

| Profile | Menus viewable |
|---|---:|
| **Employee** | **1,657** |
| HR | 1,650 |
| **Admin** | **1,500** |

**An Employee can see more of the product than an Administrator.** In tenant 3
specifically: Employee 157, HR 150, Admin 78.

So the navigation *is* filtered server-side by `can_view`
(`tblmenumasterG2gController::displaySidebarMenu`), but filtered against data that
grants everything — and grants Employees the most. Populating this table correctly
is a prerequisite for step 6 of the sequencing in §4.4, not a detail of it.

**Nothing on the API enforces it either.** Its only three consumers are:

| File | What it does |
|---|---|
| `SeedG2gDefaultViewRights.php` | seeds rows |
| `tblmenumasterG2gController.php` | CRUD on the rows |
| `tblgroupwise_rights_g2gModel.php` | the model |

No middleware, no controller guard and no policy reads it. It decides which menu
items render; it does not decide what an API call is allowed to do.

`tblindividual_rights` (per-user override) exists with the same shape and has
**0 rows**.

### 1.3 A role + scope model — correct, but confined to one module

`hrms_leave_role_permissions`, 14 rows across 2 tenants. **This is the most
important thing in this document**, because the model your brief asks for already
exists here and has been validated by a working module:

| role_name | scope | approve | view_reports | configure | bulk | escalate |
|---|---|:-:|:-:|:-:|:-:|:-:|
| Employee | **Self** | – | ✓ | – | – | – |
| **Reporting Manager** | **Team** | ✓ | ✓ | – | – | – |
| **Department Head** | **Department** | ✓ | ✓ | ✓ | – | – |
| HR Executive | **Department** | ✓ | ✓ | ✓ | – | – |
| HR Manager | **Organization** | ✓ | ✓ | ✓ | ✓ | ✓ |
| Administrator | **Organization** | ✓ | ✓ | ✓ | ✓ | ✓ |
| Executive | **Organization** | ✓ | ✓ | ✓ | ✓ | ✓ |

`LeaveWorkflowApiController.php:136` validates
`'roles.*.scope' => 'required|in:Self,Team,Department,Organization'`.

Seven roles and four scopes — exactly what the brief §7 asks for, already written
down. **But it is configuration only.** Nothing joins these `role_name` strings to
`tbluser`, so no user is ever resolved to "Reporting Manager". It is a settings
screen describing a model the rest of the system cannot use.

### 1.4 Actual server-side enforcement — two mechanisms, both narrow

| Mechanism | Scope | Model |
|---|---|---|
| `TaskPermissionMiddleware` (`task.permission:{ability}`) | 51 routes, all `/api/task-management/*` | 8 named abilities; binary Employee vs non-Employee |
| `RequireProfile` (`profile:admin,hr,...`) — added in Phase 1 | 23 write routes in Competency, Performance, Talent | Substring match on the profile name |

Everything else is either token-only (authenticated but unauthorised) or, in LMS,
was reading the caller's role from a request parameter until Phase 1 fixed it.

**Note on `RequireProfile`:** five of its routes are gated `profile:admin,hr,manager`.
No `manager` profile exists, so that term matches nothing and those routes are
effectively `admin,hr`. The gate is closed, not open — but the intent is unmet, and
it resolves itself the moment the role model below lands.

### 1.5 Summary of the gap

| Capability | Status |
|---|---|
| Role vocabulary | Exists in `hrms_leave_role_permissions`, unused elsewhere |
| Scope vocabulary (Self/Team/Dept/Org) | Exists and is validated, unused elsewhere |
| Per-menu CRUD matrix | Populated (4,879 rows), **not enforced** |
| Per-user override | Table exists, empty |
| Assignable roles | **3** — Admin, HR, Employee |
| Reporting line to resolve *Team* | **Does not exist** |
| Department head to resolve *Department* | **Does not exist** |
| Server-side enforcement | 2 narrow mechanisms, ~74 of 739 API routes |

---

## 2. Proposed role model

### 2.1 Principle

Adopt the vocabulary that already exists in `hrms_leave_role_permissions` and
promote it to a first-class, cross-module model. Do **not** invent a new set of
role names — a working module already validated these seven, and reusing them
means the leave module converges instead of diverging.

### 2.2 The roles

| # | Role | Default scope | Purpose | Exists today? |
|---:|---|---|---|---|
| 1 | **Employee** | Self | Does the work; owns their own record | ✓ |
| 2 | **Reporting Manager** | Team | Approves and rates their direct reports | ✗ **new** |
| 3 | **Department Head** | Department | Owns a department's people, capacity and sign-off | ✗ **new** |
| 4 | **HR Executive** | Department | HR operations for a department | ✗ **new** |
| 5 | **HR Manager** | Organization | Owns people processes org-wide | ✓ (as "HR") |
| 6 | **Administrator** | Organization | Owns configuration, not daily HR operations | ✓ (as "Admin") |
| 7 | **Executive** | Organization, read-only | Visibility and exception approval; no transactional rights | ✗ **new** |
| 8 | **Auditor** | Organization, read-only | Read + export only, including audit logs | ✗ **new** |

Two roles from the brief are deliberately **not** separate roles:

- **Instructor / Trainer** — a *capability on a course*, not an organisational role.
  Model it as an assignment (`course.instructor_id`), otherwise every trainer needs
  a second account.
- **Assessor / Reviewer** — likewise a *per-assessment assignment*. A Reporting
  Manager assessing their report and an external SME assessing a specialist are
  the same act with different people; a role cannot express that, an assignment can.

**Recruiter** is a genuine role, but Recruitment is one sub-module. Recommendation:
model it as **HR Executive scoped to Recruitment** rather than an eighth role,
unless you sell to customers with a dedicated TA function. → **Q-D1**.

### 2.3 Scopes

| Scope | Resolves to | Resolution requires |
|---|---|---|
| **Self** | `user.id = caller.id` | nothing — works today |
| **Team** | every user whose `reporting_manager_id` = caller, transitively for skip-level | **`tbluser.reporting_manager_id`** — does not exist |
| **Department** | every user in the caller's department, plus sub-departments | `tbluser.department_id` ✓ exists; `hrms_departments.parent_id` ✓ exists |
| **Organization** | every user in `sub_institute_id` | works today (Phase 1 made tenant resolution trustworthy) |

Scope is **orthogonal to role**: an HR Executive is Department-scoped, an HR Manager
Organization-scoped, and the same permission check ("may approve a development
plan") is answered differently for each. This is why scope must be a column and not
baked into role names.

### 2.4 The two schema additions this requires

```sql
-- 1. the reporting line. Nullable: the CEO reports to nobody.
ALTER TABLE tbluser
  ADD COLUMN reporting_manager_id BIGINT UNSIGNED NULL AFTER department_id,
  ADD INDEX idx_tbluser_reporting_manager (reporting_manager_id);

-- 2. department ownership, so Department scope has an owner
ALTER TABLE hrms_departments
  ADD COLUMN head_user_id BIGINT UNSIGNED NULL AFTER department,
  ADD INDEX idx_hrms_departments_head (head_user_id);
```

Both are additive and nullable, so nothing breaks on the way in.

**Cycle safety:** `reporting_manager_id` is self-referential, so A→B→A is possible
and would hang any recursive team query. Validate on write (walk the chain, reject
a cycle) **and** bound the recursion depth on read. This is not optional — a single
bad row would take down every Team-scoped query in the product.

### 2.5 Where the role assignment lives

`tbluser.user_profile_id` → `tbluserprofilemaster` already exists and is the natural
home. The change is to **seed seven profiles per tenant instead of three**, keeping
the existing FK.

The alternative — a many-to-many `user_roles` table — is more flexible but a bigger
change, and nothing in the brief requires one person to hold two roles
simultaneously. **Recommendation: extend the existing single-role model.** → **Q-D2**
if you foresee needing multi-role.

Add to `tbluserprofilemaster`:

```sql
ALTER TABLE tbluserprofilemaster
  ADD COLUMN role_key   VARCHAR(40)  NULL,  -- stable machine key: 'reporting_manager'
  ADD COLUMN data_scope VARCHAR(20)  NULL;  -- Self | Team | Department | Organization
```

`role_key` matters: today every guard string-matches on the **display name**
(`str_contains($profile, 'admin')`). A tenant that renames "HR" to "People Ops"
silently loses access. A stable key ends that class of bug — and it is the same
class of defect Phase 1 found repeatedly.

---

## 3. The RBAC matrix

Legend: **V** view · **C** create · **E** edit · **D** delete · **A** approve ·
**X** export · **–** no access. Scope in brackets where narrower than the role default.

### 3.1 Organizational Management

| Screen | Employee | Reporting Mgr | Dept Head | HR Exec | HR Mgr | Admin | Executive | Auditor |
|---|---|---|---|---|---|---|---|---|
| Organization Profile | V | V | V | V | V E | V C E D | V | V X |
| Department Management | – | V (dept) | V E (own dept) | V E (dept) | V C E D | V C E D | V | V X |
| Employee Directory | **V (org — basic fields)** | V (org basic) + V full (team) | V (org basic) + V full (dept) | V C E (dept) | V C E D | V C E D | V | V X |
| Role & Permissions | – | – | – | – | V | V C E D | V | V X |
| **Group-wise rights** *(SHIP)* | – | – | – | – | V | V C E D | V | V X |
| **Individual rights** *(SHIP)* | – | – | – | – | V | V C E D | V | V X |
| Compliance Library | V | V | V (dept) | V C E | V C E D | V C E D | V | V X |
| Disciplinary Library | – | V (team) | V (dept) | V C E (dept) | V C E D | V C E D | V | V X |
| **Skill Gap Analysis** *(SHIP)* | V (self) | V (team) | V (dept) | V (dept) | V X | V X | V X | V X |
| **Search Employees by Skills** *(SHIP)* | – | V (team) | V (dept) | V | V | V | V | V |

### 3.2 Competency Management

| Screen | Employee | Reporting Mgr | Dept Head | HR Exec | HR Mgr | Admin | Executive | Auditor |
|---|---|---|---|---|---|---|---|---|
| Command Center | – | – | V | V | V C | V C | V | V |
| Competency Library | V | V | V | V C E | V C E D | V C E D | V | V X |
| Library & Taxonomy | V | V | V | V C E | V C E D | V C E D | V | V X |
| Framework & Role Mapping | V (own role) | V (team roles) | V (dept) | V C E | V C E D | V C E D | V | V X |
| Assessments | V C (self-assessment) | V C E A (team) | V A (dept) | V C E (dept) | V C E D A | V C E D | V | V X |
| Employee Profiles | V E (self) | V E (team) | V (dept) | V E (dept) | V E | V E | V | V X |
| Development & Career Paths | V C (own) | V C E **A** (team) | V A (dept) | V C E (dept) | V C E D | V C E D | V | V X |
| Certifications | V (own) | V (team) | V (dept) | V C E (dept) | V C E D | V C E D | V | V X |
| Taxonomy Ontology | V | V | V | V | V C E | V C E D | V | V X |

The **A** on Development Plans is the brief's "Sign-off on individual development
plans" and cannot be expressed at all today.

### 3.3 LMS

| Screen | Employee | Reporting Mgr | Dept Head | HR Exec | HR Mgr | Admin | Executive | Auditor |
|---|---|---|---|---|---|---|---|---|
| Learning Dashboard | V (self) | V (team) | V (dept) | V (dept) | V | V | V | V X |
| Learning Catalog | V | V | V | V | V C E | V C E D | V | V |
| My Learning | V (self) | V (team) | V (dept) | V | V | V | – | V X |
| Assignments | V (own) | V C **A** (team) | V C A (dept) | V C E (dept) | V C E D | V C E D | V | V X |
| Sessions & Calendar | V, self-register | V C (team) | V C (dept) | V C E | V C E D | V C E D | V | V X |
| Certifications & Records | V (own) | V (team) | V (dept) | V C E | V C E D | V C E D | V | V X |
| Course Builder | – | – | – | V C E | V C E D | V C E D | – | V |
| Administration & Governance | – | – | – | – | V C E | V C E D | V | V X |

The **A** on Assignments is "Approve/reject team members' optional course
enrolment requests" from the LMS spec.

### 3.4 Task Management

| Screen | Employee | Reporting Mgr | Dept Head | HR Exec | HR Mgr | Admin | Executive | Auditor |
|---|---|---|---|---|---|---|---|---|
| Dashboard | V (self) | V (team) | V (dept) | V (dept) | V | V | V | V X |
| My Tasks | V C E (own) | V E (team) | V (dept) | V | V | V | – | V X |
| Projects & Workstreams | V (member) | V C E (team) | V C E D (dept) | V | V | V C E D | V | V X |
| Dependencies & Workstreams | V (own) | V C E (team) | V C E D (dept) | V | V | V C E D | V | V X |
| Task Calendar | V (self) | V (team) | V (dept) | V | V | V | V | V |
| Reports & Analysis | V (self) | V (team) | V (dept) | V X | V X | V X | V X | V X |
| Task approvals | – | **A** (team) | **A** (dept) | – | A | A | – | V |
| Status / Priority Management | – | – | – | – | V | V C E D | – | V |
| Permission | – | – | – | – | V | V C E D | – | V X |
| Integration | – | – | – | – | – | V C E D | – | V |
| Audit Log | – | – | – | V | V X | V X | V | V X |

This module's existing abilities map cleanly onto the new roles: `task.approve`,
`task.delete`, `project.*`, `dependency.manage`, `milestone.manage`,
`notification.manage` become role-derived rather than binary Employee/non-Employee.

### 3.5 Talent Management

| Screen | Employee | Reporting Mgr | Dept Head | HR Exec | HR Mgr | Admin | Executive | Auditor |
|---|---|---|---|---|---|---|---|---|
| Talent Dashboard | – | V (team) | V (dept) | V | V | V | V | V X |
| Recruitment | V (referrals) | V C (own reqs), interview scorecards | V C **A** (dept reqs) | V C E (all) | V C E D A | V C E D | V | V X |
| Onboarding | V (own checklist) | V E (team), buddy | V (dept) | V C E (dept) | V C E D | V C E D | V | V X |
| Performance Reviews | V C (self-appraisal) | V C E **A** (team) | V A (dept) | V C E (dept) | V C E D A | V C E D | V | V X |
| Mobility & Succession | V (internal jobs, apply) | V C (nominate team) | V C E (dept slates) | V C E | V C E D A | V C E D | V | V X |
| Offboarding | V (own exit) | V **A** (team resignation) | V A (dept) | V C E (dept) | V C E D | V C E D | V | V X |
| Administration | – | – | – | V | V C E | V C E D | V | V X |

### 3.6 HRIT

| Screen | Employee | Reporting Mgr | Dept Head | HR Exec | HR Mgr | Admin | Executive | Auditor |
|---|---|---|---|---|---|---|---|---|
| Attendance Tracking | V C (own punch) | V (team) | V (dept) | V E (dept) | V E | V E | V | V X |
| Attendance Reports | V (self) | V (team) | V (dept) | V X | V X | V X | V X | V X |
| Leave Dashboard / Requests | V C (own) | V **A** (team) | V A (dept) | V A (dept) | V A | V A | V | V X |
| Leave Reports | V (self) | V (team) | V (dept) | V X | V X | V X | V X | V X |
| Leave Configuration / **Allocation** *(SHIP)* | – | – | – | V E | V C E D | V C E D | – | V X |
| **Holiday Master** *(SHIP)* | V | V | V | V E | V C E D | V C E D | V | V X |
| Payroll (all 7 screens) | V (own payslip) | – | – | V (dept) | V C E | V C E D | V | V X |

Payroll is deliberately **not** visible to Reporting Manager or Department Head.
Compensation visibility is a distinct trust boundary from performance management,
and conflating them is a common and expensive mistake.

### 3.7 Consolidated Reports and Agentic AI

| Screen | Employee | Reporting Mgr | Dept Head | HR Exec | HR Mgr | Admin | Executive | Auditor |
|---|---|---|---|---|---|---|---|---|
| Consolidated Reports | V (self) | V X (team) | V X (dept) | V X (dept) | V X | V X | V X | V X |
| — competency gap report | V (self) | V X (team) | V X (dept) | V X | V X | V X | V X | V X |
| — development plan report | V (self) | V X (team) | V X (dept) | V X | V X | V X | V X | V X |
| — certification expiry report | V (self) | V X (team) | V X (dept) | V X | V X | V X | V X | V X |
| Agentic AI (all 8) | – | – | – | – | V | V C E D | V | V X |

The three named reports are the ones your Reporting decision requires and that
none of the 45 legacy reports covered.

---

## 3.8 Field-level permissions (A2)

Screen-level view/edit is not sufficient. An Employee may legitimately open the
Employee Directory and must not see a colleague's salary; a Reporting Manager
writes review comments their report must never read. **Where a screen mixes
sensitivities, the permission is on the field, not the screen.**

Notation: **R** read · **W** write · **–** not returned by the API at all.
"Not returned" matters: hiding a field in the UI while the endpoint still returns
it is the exact defect class Phase 1 found repeatedly.

### 3.8.1 Employee record (`tbluser`, 98 columns)

| Field group | Self | Reporting Mgr (team) | Dept Head (dept) | HR Exec | HR Mgr | Admin | Executive | Auditor |
|---|---|---|---|---|---|---|---|---|
| **Basic directory** — name, job title, department, work email, work phone, manager, photo | R (all org) | R (all org) | R (all org) | R W | R W | R W | R | R |
| Employee no., join date, grade, location | R | R | R | R W | R W | R W | R | R |
| **Personal contact** — home address, personal mobile, personal email | R W | – | – | R W | R W | R | – | R |
| **Identity docs** — Aadhaar/national id, PAN, passport, bank details | R (masked) | – | – | R (masked) | R W | – | – | R (masked) |
| **Salary / CTC / grade band** | R (own) | – | – | – | R W | R | R (aggregate only) | R |
| Date of birth, marital status, dependants | R W | R (DOB day/month only) | – | R W | R W | R | – | R |
| Emergency contact | R W | R | R | R W | R W | R | – | R |
| `status`, `terminated_date`, `termination_reason` | R (own status) | R (status only) | R | R W | R W | R W | R | R |
| Login/audit — `plain_password`, tokens, session data | – | – | – | – | – | – | – | – |

`plain_password` is never returned to anyone. It was flagged in the earlier audit
and remains a live concern for any table-data endpoint.

### 3.8.2 Performance review

| Field | Reviewee | Reporting Mgr | Dept Head | HR Mgr | Auditor |
|---|---|---|---|---|---|
| Self-appraisal text | R W | R | R | R | R |
| Manager rating | R **after release** | R W | R | R W | R |
| **Manager private comments / calibration notes** | **–** | R W | R | R | R |
| Final rating | R after release | R W | R | R W | R |
| Increment / bonus recommendation | R after release | W | R | R W | R |
| Calibration session notes | – | R | R W | R W | R |

The **release** gate is the point: a rating exists before it is shared. Without a
`released_at`, a reviewee can read a draft rating the moment it is saved.

### 3.8.3 Competency ratings

| Field | Self | Reporting Mgr | Dept Head | HR Mgr | Peer |
|---|---|---|---|---|---|
| Own current proficiency | R | R (team) | R (dept) | R | – |
| Own gap vs required | R | R (team) | R (dept) | R | – |
| **Assessor identity on a 360 input** | – | R | R | R | – |
| Assessor free-text feedback | R (anonymised) | R W | R | R | W (own input) |
| Colleague's proficiency | – | R (team) | R (dept) | R | **–** |
| Evidence records (Q-B3 task signals) | R (own) | R W (team) | R (dept) | R | – |

360-degree feedback is worthless if the reviewee can identify who said what.
Anonymisation is a field-level rule, not a UI choice.

### 3.8.4 Payslip

| Field | Self | Reporting Mgr | Dept Head | HR Exec | HR Mgr | Auditor |
|---|---|---|---|---|---|---|
| Own payslip, all components | R | – | – | – | R | R |
| Team payslips | – | **–** | **–** | R (dept) | R | R |
| Bank account | R | – | – | – | R | R (masked) |

Consistent with the approved decision to keep payroll away from line management.

### 3.8.5 How field-level rules get enforced

Three options; recommendation is (c):

| Option | Assessment |
|---|---|
| (a) Per-field rows in the rights table | Most precise, highest maintenance; 98 columns × 9 roles per screen is unmanageable by hand |
| (b) Hardcoded in each controller | Fast, unauditable, drifts immediately |
| (c) **Named field groups** (`basic`, `personal`, `identity`, `compensation`, `private_notes`) with a group→role grant, applied by an API resource layer | Auditable, few rows, and the resource layer means a field is *absent from the payload*, not merely hidden |

(c) also gives one place to enforce masking (`XXXX-XXXX-1234`) rather than
scattering it.

---

## 4. How this gets enforced

Enforcement must be **server-side**. The Phase 1 audit found the same defect
repeatedly: a UI that hides a control while the endpoint stays open.

### 4.1 Three layers, each with one job

| Layer | Question | Mechanism |
|---|---|---|
| 1. Authentication | Who is calling? | `ResolvesApiIdentity` — built and verified in Phase 1 |
| 2. Authorisation | May this role do this? | **New** `permission:{module}.{action}` middleware, reading `tblgroupwise_rights_g2g` |
| 3. Data scope | Which rows? | **New** query scope resolving Self / Team / Department / Organization |

Layer 3 is the one usually forgotten, and it is where the real risk sits. A
Reporting Manager legitimately has `can_view` on Employee Directory; without a
scope filter that becomes *every employee in the tenant*.

### 4.2 Populate the matrix, then read it

> **CORRECTED 2026-08-05 (G-SEC-07).** This section previously said the matrix
> "needs a reader, not a replacement". That was wrong. It has the right *shape*
> but carries **no information**: `can_view` = 1 on all 4,879 rows,
> `can_add`/`can_edit`/`can_delete` = 0 everywhere. Switching on a reader against
> this data would grant every role view of everything and edit of nothing.
> **Populating it is a prerequisite step of the G-SEC-01 fix, not a detail of it.**

**Seed source: §3.1–§3.7 of this document.** Those matrices are already decided and
approved — 9 roles × live screens × V/C/E/D/A/X. They become a seeder; the
permissions are **not** to be re-derived from scratch.

```
§3.1–3.7 tables  →  seeder  →  tblgroupwise_rights_g2g
                                (menu_id × profile_id × tri-state per action)
```

Two consequences:

- The seeder writes **ALLOW / DENY / INHERIT** per action (G-SEC-06), not the
  current boolean, so both rights tables share one shape and one resolver.
- Screens marked `–` in §3.1–3.7 become an explicit **DENY**, not an absent row.
  An absent row means *inherit*, which is not the same statement.

Only once the data means something does the reader make sense:

```php
// middleware: permission:employee-directory,view
$right = GroupwiseRight::where('menu_id', $menuId)
    ->where('profile_id', $user->user_profile_id)
    ->first();
abort_unless($right && $right->can_view, 403);
```

### 4.3 Scope resolution

```php
// applied to every list query
match ($profile->data_scope) {
    'Self'         => $q->where('user_id', $me->id),
    'Team'         => $q->whereIn('user_id', $this->teamOf($me->id)),   // recursive, depth-bounded
    'Department'   => $q->whereIn('department_id', $this->deptTreeOf($me->department_id)),
    'Organization' => $q,   // tenant filter already applied by ResolvesApiIdentity
};
```

`teamOf()` must be depth-bounded and cycle-safe (§2.4).

### 4.4 Sequencing

| # | Step | Depends on |
|---:|---|---|
| 1 | Add `role_key` + `data_scope` to `tbluserprofilemaster`; backfill the 3 existing profiles | — |
| 2 | Add `tbluser.reporting_manager_id` + `hrms_departments.head_user_id` | — |
| 3 | Seed the 7 profiles per tenant; keep existing users on their current role | 1 |
| 4 | Build scope resolution + cycle validation | 2 |
| 5 | Build `permission:` middleware over `tblgroupwise_rights_g2g` | 1 |
| 6 | Populate rights rows for the 4 new profiles | 3, 5 |
| 7 | Apply to routes, highest-risk first (Competency writes, Performance, Talent) | 5, 6 |
| 8 | Replace `RequireProfile`'s `admin,hr,manager` strings with `role_key` checks | 1, 7 |
| 9 | Frontend reads the same rights to hide controls — **presentation only** | 5 |

> **CORRECTED 2026-08-05 (G-SEC-07).** This section previously claimed "nothing
> before step 7 changes behaviour for an existing user". **That is false.** The
> sidebar is filtered live by `can_view`
> (`tblmenumasterG2gController::displaySidebarMenu`), so the moment step 6
> populates the rights rows, **every user's navigation visibly changes** — before
> any API enforcement exists. Steps 1–2 remain additive and safe; step 6 is a
> user-visible release and must be treated as one.

### 4.5 Rollout plan for step 6 (populating the rights matrix)

Because step 6 is user-visible, it ships behind a review gate rather than as a
migration.

| # | Step | Output |
|---:|---|---|
| 1 | Run the seeder against a **non-production tenant** | Rights rows for 9 roles |
| 2 | Generate a **per-role before/after menu diff** | One table per role: menus visible now, menus visible after, **and every menu lost** |
| 3 | **Triz reviews the diff** — explicitly confirming no role loses a screen it legitimately needs | Sign-off |
| 4 | Amend the §3.1–3.7 seed source where the diff is wrong | Corrected seeder |
| 5 | Re-run steps 1–3 until the diff is accepted | — |
| 6 | Roll out per tenant, with the backup/rollback template from `_changes/G-NAV-01-*` | Reversible per tenant |

**Expected magnitude — this is why the review matters:**

| Role | Menus visible today | After (approx, from §3.1–3.7) | Change |
|---|---:|---:|---|
| Employee | **1,657** | ~25–30 per tenant | **large drop** |
| HR | 1,650 | ~60 | large drop |
| Admin | 1,500 | ~70 | large drop |

An Employee currently sees more of the product than an Administrator, so the
Employee diff will be the largest and the most likely to remove something people
have quietly come to rely on. **That list is reviewed before it ships, not after a
user complains.**

Two rules for the diff review:

- **A lost screen is a finding, not a side effect.** Every removal is listed
  explicitly and individually confirmed.
- **Self-service screens are the risk area** — leave, attendance, payslip, my
  learning, my tasks. If any of those appear in an Employee's "lost" column, the
  seed source is wrong, not the user.

---

## 5. Migration impact on the 386 existing users

| Today | Becomes | Users | Rationale |
|---|---|---:|---|
| Employee | Employee | 238 | unchanged |
| Admin | Administrator | 76 | unchanged in substance |
| HR | HR Manager | 72 | Organization scope matches what HR does today |

**No user loses access.** The four new roles start empty and are assigned
deliberately. Reporting Manager in particular only becomes meaningful once
`reporting_manager_id` is populated — which is a data exercise the customer must
do, and a good candidate for the same import flow as Q-C1's job-role library.

---

## 5A. Amendments A3–A7 (approved 2026-08-05)

### A3 — Route-to-menu map · **delivered**

`tblgroupwise_rights_g2g` is keyed by `menu_id`, but the product has **739 API
routes** against **~75 menus**. Permission middleware cannot be applied until every
route is mapped to the menu whose rights govern it.

> **Counting method** is defined once in `07-gap-register.md` §"Counting method".
> All route figures in Phase 3 use it. An earlier draft of this section said 687;
> that came from a parser that missed 52 fully-qualified route declarations and is
> corrected here.

Generated by `scripts/audit-authorization.py --csv` →
`_evidence/route-to-menu-map.csv` (every route, its controller::method, its
authorization state, its mapped menu and a confidence score) and
`_evidence/authorization-coverage.json`.

**Result:**

| | Routes |
|---|---:|
| Map to no menu at all | **208** → `G-SEC-02` |
| Map at confidence 1 (one shared token — **unreliable**) | **~329** → `G-SEC-04` |
| Map at confidence ≥ 2 (safe to auto-apply) | ~200 |

**Only confidence ≥ 2 may be auto-applied.** Confidence 0 *and* 1 both count as
unmapped, so **~537 of 739 routes need an explicit declaration** — that is the real
size of the A3 task. A wrong mapping is worse than a missing one because it reads
as enforced; see `G-SEC-04` for the sampled errors.

**A route may never map to a container or module-root menu row — leaf screens
only.** A rights check against a module root cannot distinguish view from delete.

Worst offenders among the 208 with no menu at all:

| Controller | Unmapped routes |
|---|---:|
| `Agentic\WorkflowController` | 13 |
| `assignment\assignmentController` | 11 |
| `Offboarding\OffboardingController` | 11 |

Three reasons a route maps to nothing, needing different fixes:

1. **The menu exists but the URI shares no vocabulary with it** — mapping is a
   naming problem; add an explicit `menu_id` annotation per route group.
2. **The feature has no menu** (`TaskOptionController`, `HolidayApiController`) —
   sub-resources of a screen; inherit the parent screen's menu.
3. **The menu is disabled** (Agentic's 27 routes are live while `Pal` and several
   siblings are not) — resolve via the Q-A3 triage.

**Recommendation:** make the mapping explicit in `routes/api.php` rather than
inferred — `->defaults('menu', 'employee-directory')` on each group. Inference by
string similarity is fine for an audit and unacceptable as an access-control
input.

### A4 — Delegation / acting manager · **recorded, deferred**

When an approver is absent, approvals must be delegable to a named substitute for
a date range. **Not Phase 3 build work**; recorded in the model and in deferred
scope.

Shape for later:

```sql
CREATE TABLE approval_delegation (
  id, sub_institute_id,
  delegator_user_id,     -- who is away
  delegate_user_id,      -- who acts for them
  scope_type,            -- all | module | menu
  scope_ref,             -- null | module key | menu_id
  starts_on, ends_on,
  reason, created_by, created_at, revoked_at
);
```

Two rules that must be designed in from the start, because retrofitting them is
painful:

- **The audit trail records both parties** — "approved by B **acting for** A",
  never just B. An approval that hides the delegation is an audit finding.
- **Delegation does not widen scope.** A delegate acting for a Department Head
  gets that Department Head's scope, not their own plus it.

### A5 — Skip-level is a tenant setting · **approved**

Team scope must not assume a depth. New setting, default **direct reports only**:

| Setting | Values | Default |
|---|---|---|
| `team_scope_depth` | `direct` \| `all_descendants` \| integer depth | `direct` |

Tenant-level, per Q-B3's principle that thresholds are configurable rather than
hardcoded. A 6-person startup and a 400-person manufacturer want different answers.

**Cycle validation and depth bounding are confirmed as step 4 work**, not later:

- **On write:** walk the proposed chain before saving; reject if it revisits the
  employee. Also reject self-management.
- **On read:** hard depth cap (recommend 10) regardless of the setting, so a bad
  row degrades a result instead of hanging a request.
- **On import:** the same validation, applied per row — bulk employee import is
  the most likely way a cycle enters.

### A6 — Individual rights precedence · **approved**

Written into the model before `tblindividual_rights` (currently 0 rows) is used:

**Resolution order — first match wins** *(revised 2026-08-05 per G-SEC-06; the
original four-step order had no group-level DENY, so a role could not be denied
something its scope would otherwise grant)*:

1. **Individual DENY** → denied. Nothing overrides this.
2. **Group DENY** → denied.
3. **Individual ALLOW** → allowed.
4. **Group ALLOW** → allowed.
5. **Role default** → as defined by the role.
6. **Otherwise** → denied.

Two consequences to build in:

- **Tri-state values are required, on BOTH tables.** Today the columns are
  `can_view/can_add/can_edit/can_delete` where `0` is indistinguishable from "no
  row", so DENY cannot be expressed at all. Each action becomes
  **ALLOW / DENY / INHERIT**, with INHERIT the default and an absent row also
  meaning inherit. Applied to `tblgroupwise_rights_g2g` as well as
  `tblindividual_rights`, so the two tables share one shape and one resolver.
  → `G-SEC-06`
- **Deny always wins over any grant, at any level.** Stated plainly because the
  opposite convention is common and produces privilege escalation.

Scope is **not** overridable individually. Granting one Employee a screen must not
silently widen them from Self to Organization — scope stays role-derived.

### A7 — Leave module convergence · **scheduled**

Two role systems must not persist. `hrms_leave_role_permissions` holds the
vocabulary being adopted; it must become a consumer of the shared model, not a
parallel one.

| Phase | Step | When |
|---|---|---|
| 1 | Add `role_key` to `hrms_leave_role_permissions`, backfill by name match (all 7 names match exactly) | With RBAC step 1 |
| 2 | Leave workflow resolves the approver's role via `tbluserprofilemaster.role_key`, not the local `role_name` string | After RBAC step 7 |
| 3 | Local `approve_leave` / `view_reports` / `configure_settings` / `bulk_operations` / `escalation_rights` flags become rows in `tblgroupwise_rights_g2g` for the Leave menus | Post-Phase 3 |
| 4 | Drop `hrms_leave_role_permissions`; Leave reads the shared matrix | Post-Phase 3, after a release of parallel running |

**Scheduled, not assumed.** Steps 1–2 are inside Phase 3; 3–4 are the first
post-Phase-3 items. Until step 4, treat the leave table as **read-only
configuration** — no new writers.

---

## 5B. Answers to Q-D1 / Q-D2 / Q-D3

### Q-D1 — Recruiter is role 9 · `ANSWERED`

Accepted. A talent-acquisition team that must not see performance or payroll is a
common corporate structure, and the per-menu matrix makes it nearly free.

| # | Role | Default scope | Notes |
|---:|---|---|---|
| 9 | **Recruiter** | Organization, **restricted to Recruitment** | Full CRUD on requisitions, candidates, interviews, offers. **No** access to Performance, Payroll, Competency ratings or Employee Directory personal/compensation fields |

Matrix rows for Recruiter across the modules:

| Module | Recruiter |
|---|---|
| Talent → Recruitment | V C E D A |
| Talent → Onboarding | V (handover of hired candidates only) |
| Organization → Employee Directory | V (basic fields only, per A1) |
| Competency → Framework & Role Mapping | V (to read required competencies for a requisition) |
| Competency → Employee Profiles / ratings | **–** |
| Talent → Performance | **–** |
| HRIT → Payroll | **–** |
| Everything else | – |

Note the one read Recruiter **does** need: the job-role competency requirements, so
a requisition and its interview scorecard can be generated from the framework
rather than retyped (golden thread 7).

### Q-D2 — Single role, collection-shaped accessor · `ANSWERED`

Accepted with the condition. **All authorization code resolves roles through one
accessor returning a collection**, even while that collection always holds exactly
one role.

```php
// The ONLY sanctioned way to read a caller's roles.
// Returns a collection today holding one role; a later user_roles table
// changes this method's body and nothing else.
public function rolesOf(User $user): Collection

// Every check goes through these, never through user_profile_id directly:
$this->rolesOf($user)->contains(fn ($r) => $r->role_key === 'hr_manager');
$this->widestScope($user);   // max(scope) across roles
```

**Prohibited:** any direct `user_profile_id` comparison in a controller. That is
what makes the later migration a data change rather than a rewrite.

This also retires the existing `str_contains($profile, 'admin')` pattern, which is
the same defect in a different costume — it breaks the moment a tenant renames a
profile.

### Q-D3 — Executive and Auditor stay separate · `ANSWERED`

Accepted.

| Role | Sees | Does not see |
|---|---|---|
| **Executive** | Dashboards, aggregates, exception approvals | **Audit logs**; no transactional rights |
| **Auditor** | Everything readable **including audit logs**, plus export | No writes of any kind, no approvals |

Segregation of duties is the whole point of an auditor role; an executive who can
read the audit trail can check whether their own actions were noticed.

---

## 6. Questions raised here — all answered

**Q-D1, Q-D2 and Q-D3 were answered on 2026-08-05. The decisions and their
consequences are in §5B above.** An earlier draft of this section still listed
them as OPEN, which contradicted §5B; that contradiction is removed rather than
duplicated, so there is one statement of each decision in this document.

The canonical register of every question and its status is
`10-open-questions.md`.

---

## 7. Verification status

| Claim | Method | Status |
|---|---|---|
| 3 profiles only, 386 users | `tbluserprofilemaster` + `tbluser` join | **Verified** |
| `tblgroupwise_rights_g2g` = 4,879 rows, 3 consumers, no enforcement | Live count + grep across `app/` | **Verified** |
| `tblindividual_rights` empty | Live count | **Verified** |
| Role+scope model exists in `hrms_leave_role_permissions` | Live query, 14 rows | **Verified** |
| Scope enum validated `Self,Team,Department,Organization` | `LeaveWorkflowApiController.php:136` | **Verified** |
| No `reporting_manager_id` on `tbluser` | `information_schema` column scan | **Verified** |
| No department head column | `hrms_departments` column list | **Verified** |
| 7 modules each hold their own `manager_id` | `information_schema` scan | **Verified** |
| `RequireProfile` `manager` term matches nothing | Profile list vs middleware args | **Verified** |
