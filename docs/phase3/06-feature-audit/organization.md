# Module write-up 2 — **ORGANIZATION** (5 sub-modules)

**C18 format.** Sweep hits · hand-read of the primary config and controller ·
C35 payload checklist (both directions) · §5.1 reconciliation · CONNECTIONS TO BUILD.

**Status:** `Analysis Done`. No code changed by this document.

Sub-modules: Organization Profile · Department Management · **Employee Directory**
· Role & Permissions · Compliance / Disciplinary Library.

---

## 1. Sweep hits landing in this module

| Sweep | Hits | Consequence |
|---|---|---|
| **S-1** (verified) | `hrms_departments.department` → list **(b) own identity, NOT a defect**; `s_user_jobrole.department` + `sub_department` → **have** a matching `department_id`, so they are the *populated* half of L-01 | Organization **owns** the table the whole product mis-joins to |
| **C23 guard** (executed) | 1 FAIL each: `DepartmentManagementController`, `organizationDetailsController`, `EmployeeDirectoryAnalyticsController`, `tblmenumasterG2gController`, `masterSetupController`, `CustomModuleController`, `TemplateController`, `AJAXController` | **8 candidates**, none source-corroborated yet (R6) |
| **C34** (uncalibrated) | included in the 114 | **Not quoted** pending C37 |

**Organization is the module the rest of the product depends on and does not
reference.** `hrms_departments` is correct, populated, and keyed — and
`s_users_skills`/`s_user_jobrole` write a *name* beside the `department_id` they
already have (**L-01/L-02**).

---

## 2. Employee Directory — **the §5.1 key unknown, answered**

The brief named the Competency Mapping section here as the key unknown. It is
**present and wired**, not absent.

`employee-directory-sheets.tsx` lazy-loads **`JobroleSkillTab`** (line 39) and
**`CompetencyRatingTab`** (line 57), and imports `fetchCompetencyProfile` (line 20)
and **`updateSkillRating`** (line 22).

> **So Organization can write competency ratings.** This is a **second writer** of
> capability data, alongside Competency's own screens.

Two consequences, and the second is the finding:

1. **The chain exists** — an employee's job role and skill ratings are editable from the directory.
2. **`employee-directory.tsx:211,217` computes a "Skill Deficit" KPI as `!employee.skills || employee.skills.length === 0`.** It reports *"N of M employees lack competency mapping"* — **derived from whether an array came back non-empty**, not from any readiness or coverage rule. With capability coverage measured at **3.0%** (`G-DATA-05`), this KPI reads ~97% on real data and is presented as an organisational metric.

**Not raised as a new gap** — it is `G-DATA-05` surfacing in a screen. Recorded so
the number is not mistaken for a measurement.

---

## 3. Role & Permissions — the module owns the S1 that blocks the RBAC fix

**`G-SEC-07` lives here.** `can_view` = 1 on **all 4,879 rows**; every other action
column = 0; Employee sees **1,657** menus against Admin's **1,500**.

**The matrix is not merely wrong, it is uninformative** — enforcing it as it stands
would grant everyone everything. Populating it correctly (`03-rbac-matrix.md`
§3.1–3.7) is a **prerequisite** to `G-SEC-01`'s fix, not a detail of it, and it is
**Gate D item 8**.

This is the module's largest item and it is **already fully specified**. Nothing
new is proposed here.

---

## 4. C35 checklist — payload vs validator vs insert, **both directions**

Per **R1**, this checklist independently corroborated **L-01** from the opposite
direction in the Competency module. Applied here looking **both** ways.

| Form | Files read | Verdict |
|---|---|---|
| Department create/edit | `department-management.tsx` · `DepartmentManagementController.php` (validator) · same (insert) | ⚠️ **1 C23 FAIL on this controller** — payload not yet the issue; tenant resolution is. Deferred to the guard worklist |
| Organization profile | `organization-profile.tsx` · `organizationDetailsController.php` · same | ⚠️ 1 C23 FAIL, same treatment |
| Employee edit → Jobrole/Skill tabs | `jobrole-skill-tab.tsx` · `employeeDetailController` / `updateSkillRating` path · `s_skill_matrix` insert | ⚠️ **Writes `s_skill_matrix`, which has NO tenant column** (`G-DATA-08`). The write is scoped by the employee record it hangs off, not by a column — **exactly the case the new `sub_institute_id` is for** |
| Role & permission assignment | `tblgroupwise_rightsController` / `tblindividual_rightsController` | ✅ **`user_profile_id` from the request is CORRECT here** — it is the *subject being configured*, not the caller. This is the documented S-3 false-positive class, confirmed |

**The fourth row matters:** it is the first time the "subject vs identity"
distinction has been **confirmed in the direction that clears a controller**,
rather than used to caveat a count.

---

## 5. §5.1 — new work versus already-approved work

| Item | Verdict | Maps to |
|---|---|---|
| `department_id` written by Competency forms | **ALREADY APPROVED** | L-01 / L-02, Gate D item 5 |
| Rights matrix population | **ALREADY APPROVED** | `03-rbac-matrix.md` §4.5, Gate D item 8 |
| `reporting_manager_id` / `head_user_id` | **ALREADY APPROVED** | Q-B1, §10 step 2, Gate D item 7 |
| `skill_matrix_item.sub_institute_id` | **ALREADY APPROVED** | G-DATA-08 decision, §10 step 12 |
| Skill-Deficit KPI semantics | **NEW** | trivial; see O-01 |
| 8 C23 guard candidates | **ALREADY SCHEDULED** | part of the 46, worked by data sensitivity |

**Tally: 1 new, 5 already approved.** **This module proposes almost no new work** —
because its defects are the *causes* of the others, and they were specified in
Gate B before Gate C began. That is the design working, not a thin audit.

---

## 6. CONNECTIONS TO BUILD

| # | Connection | From → To | Why it matters to a buyer | Cost | Blocked by | Evidence |
|---|---|---|---|---|---|---|
| **O-01** | Make "Skill Deficit" state its rule, or drop it | within Organization | It reports *"N of M employees lack competency mapping"* from an empty-array test. On real data it reads ~97% and looks like a measurement | **XS** | — | `employee-directory.tsx:211,217`; `G-DATA-05` |
| **O-02** | Employee Directory's rating writes go through the same service as Competency's | Organization → Competency | **Two independent writers of capability data.** Once `skill_matrix_item` exists they must not diverge | **S** | §10 step 12 | `employee-directory-sheets.tsx:22,57` |

**Deliberately NOT proposed:** anything touching the rights matrix, the reporting
line, or `department_id` — all three are specified and scheduled; re-proposing them
here would double-count Gate D.

---

## 7. Status

`Analysis Done`. **5 sub-modules.** The §5.1 key unknown is **answered**: Employee
Directory *can* write competency ratings, making Organization a second writer of
capability data. 2 connections, both small. 8 guard candidates handed to the
security worklist.

**Module count: 14 of 32.** Next: LMS (3), Task (2), Talent (7), Other (4).
