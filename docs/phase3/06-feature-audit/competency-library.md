# Competency → Competency Library (+ Command Center creation path)

**Gate C write-up 2 of N.** C2 golden-thread order, item 2.
**Status:** `Analysis Done` — no code changed.

| | |
|---|---|
| **Screen** | Competency → Competency Library (`cm-competency-library.tsx`), plus the Command Center quick-create that writes the same table |
| **Backend** | `libraries/skillLibraryController.php` (Library), `Api/Competency/CompetencyController.php` (Command Center), `ApprovalController.php`, `RoleMappingController.php` |
| **Table written** | **`s_users_skills`** — the same table as Libraries & Taxonomy's Skill tab |
| **Inventory rows in scope** | **78** |
| **Connections** | **11** (C-01…C-11) — 4 new, 4 already approved, 3 other |
| **Inherits** | Gate A duplication **D2** |

---

## 0. Verification performed

| Claim class | Method | Result |
|---|---|---|
| Structural | `verify-inventory.py` over the unit | 0 real errors |
| **Security / approval negatives** | **100% (C6)** — every claim read in source | **4 of 4 TRUE, 1 UNDERSTATED** (§3, S1) |
| **Against prior decisions (C6b)** | cross-read `02-domain-model.md`, `03-rbac-matrix.md`, `07-gap-register.md` | 4 connections already approved; 2 findings are **new evidence for existing gaps**, not new gaps |

C6b caught something this time before it became an error: the RBAC finding [10]
looks like a new discovery but is **G-SEC-01** — already the most serious item in
the register. It is logged as evidence, **not** as a new gap, and not as new
Gate D scope.

---

## 1. The customer's question, answered

The customer suspected Command Center and Library were two disconnected stores.

**On storage they are wrong, and it should be said plainly.** Both write
`s_users_skills`. Command Center POSTs `/competency/competencies` →
`CompetencyController::store` (line 97); Library POSTs `/skill_library/competency`
→ `skillLibraryController::competencyLibraryStore` (line 2517). A competency created
in one **does** appear in the other.

**On fields they are right, and worse than they think.** The two forms disagree
about what a competency *is*, and the Command Center path **silently discards
data**.

---

## 2. Two creators, three disagreements — all verified

### 2.1 Silent field loss · **CONFIRMED**

Command Center's quick-create sends `{name, category, competency_type,
department_id, jobrole, status}` (`cm-command-center.tsx:217`).

Its validator accepts **five** fields — `name`, `description`, `category`,
`status`, `department_id` (`CompetencyController.php:81-87`) — and the insert at
lines 97-109 writes only those.

**`competency_type` and `jobrole` are neither validated nor inserted. They are
dropped without an error.** A user picks *"Behavioural"* from a dropdown, saves
successfully, and nothing is stored. The Library form sends 15 fields and keeps
all 15.

### 2.2 Status vocabularies that disagree · **CONFIRMED**

`CompetencyController.php:104`:
```php
'approve_status' => $request->input('status') === 'published' ? 'Approved' : 'Pending',
```

Command Center offers **Published / Draft / Archived**. Anything that is not
`published` becomes **Pending**. So **choosing "Archived" produces a Pending
competency** — the opposite of archiving. The Library meanwhile uses
Approved / Pending / Cancelled and *displays* Cancelled as "Archived".

Three words for two states across two screens, and one of the mappings is simply
wrong.

### 2.3 Two competency taxonomies in one module · **CONFIRMED**

| Screen | Values |
|---|---|
| Command Center | `technical / behavioural / leadership / functional / core` |
| Library | `Behavior / Skill / Ability / Attitude / Knowledge` — the **KASA** set |

They share no member. Even if §2.1 were fixed and `competency_type` were saved, the
Command Center value **could never match** the Library's Type filter or column.

**Note the spelling clash:** the UI stores `Behavior`; `s_skill_matrix.type` is
`ENUM('skill','knowledge','ability','attitude','behaviour')`. Definition side and
measurement side disagree on the spelling of a dimension, so **the KASA type never
reaches the measurement store** — the ownership model says Competency both *defines*
and *measures* this dimension, and the two halves share no key.

### 2.4 Department stored two incompatible ways · **CONFIRMED**

| Creator | Writes |
|---|---|
| Command Center | `department_id` (numeric) — **and no name** |
| Library | the department **name string** — `department_id` never populated (no such field on the form) |

The Command Center's *Total Competencies* tile filters on `department_id`
(`CommandCenterService.php:120-122`). So a **Library-created competency can never
match the Department filter**, and a Command-Center-created one shows a **blank
Department** in the Library edit form.

This is D1 from the previous write-up, appearing a second time in a different
module. It is the same root cause and the same fix shape (L-01/L-02).

---

## 3. The approval workflow is not a workflow · **S1**

Four claims checked in source. **All true; one understated.**

### 3.1 Every competency is born Approved — and the client chooses

The inventory said create *defaults* `approve_status` to `Approved`. It is stronger
than that. `skillLibraryController.php:2527`:

```php
'approve_status' => $request->input('status', 'Approved'),
```

**The client supplies `approve_status` directly.** `Approved` is merely the
fallback when the field is absent — which it always is, because the create form
deliberately omits Status (line 438). There is no server-side constraint on what a
caller may send.

### 3.2 Edit writes approval state straight through

`skillLibraryController.php:2587-2589`:
```php
if ($request->filled('status')) {
    $update['approve_status'] = $request->input('status');
}
```
No validation, no reviewer, no queue row, no role check. The edit form exposes a
Status dropdown (`cm-competency-library.tsx:501-506`) that reaches it.

### 3.3 Restore launders approval state

`skillLibraryController.php:2222-2223`:
```php
$restore = filter_var($request->input('restore', false), FILTER_VALIDATE_BOOLEAN);
$status  = $restore ? 'Approved' : 'Cancelled';
```
**Restore is unconditionally `Approved`.** Archive a Pending competency, restore it,
and it is now Approved with no reviewer recorded — a second bypass reachable by two
clicks.

### 3.4 Rejection is a dead end

`ApprovalController::store` sets the subject to `Pending` on submit (lines 255-259).
On **reject** it marks the approval row `rejected` but **leaves the competency at
`Pending`** (lines 316-332 touch the subject only when approving). The Library then
hides *"Submit for Approval"* whenever status is Pending
(`cm-competency-library.tsx:1223`).

**A rejected competency can never be resubmitted from this screen.** The only escape
is the self-approval dropdown in §3.2 — the workflow's failure mode is to push
users toward the bypass.

### 3.5 What this adds up to

The approval mechanism **exists on the backend and works** — `PUT
/api/competency/approvals/{id}`, plus bulk-approve. But it is **optional in four
independent ways**, and the only UI that can approve or reject
(`audit/approval-queue.tsx`) lives inside the Audit & Activity Center, which
`content-map-m2.ts:17-20` states **has no `tblmenumaster_g2g` row** — so it may not
be reachable from the sidebar at all.

**C6b check:** the *absence of role checks* here is **G-SEC-01**, already registered
as S1. Logged as evidence. The **four bypasses and the rejection dead end** are
distinct — they are workflow-integrity defects that would survive a perfect RBAC
fix — and are raised as **G-COMP-01**.

---

## 4. Navigation is dead in both screens, by two different wrong schemes

| Screen | Builds | Why it fails |
|---|---|---|
| Command Center | `/module/competency-management/${submenuId}/${submenuId}` | `CONTENT_MAP_LOADERS` is keyed `'1','2','3','4','5','204','186'`; `competency-management` is not a key. `parseRoutePath` returns null (`use-sidebar-navigation.ts:152-155`) |
| Library drawer | `/module/m2/${submenuId}/${submenuId}?competency_id=…` | `'m2'` is not a key — **`'2'` is** |

**Command Center:** all 6 "View all" tile links, all 4 ring "View details" links and
all 5 work-queue rows are dead.
**Library:** *Employees Rated*, *Development Plans*, *Certifications* and *Learning
Assigned* all land on a placeholder. The same broken scheme appears in
`approval-queue.tsx:72` and `cm-audit.tsx:319`.

**Two screens invented two different URL builders and both are wrong.** That is the
finding — not the individual links. There is no shared navigation helper, so every
screen is free to invent a scheme that compiles and fails at runtime.

---

## 5. Other verified defects

### D-C1 — "Create Role Mapping" creates a framework

`QUICK_ACTIONS` maps it to `kind:'framework'` (`cm-command-center.tsx:56`), which
POSTs `/competency/frameworks` and inserts into `s_competency_frameworks`. **No row
is ever written to `s_user_skill_jobrole`.** A real role-mapping API exists and is
ignored (`PUT /competency/role-mapping/cell`, `routes/api.php:449`).

The user clicks *"Create Role Mapping"*, gets a framework, and the **"Job Roles
Mapped" KPI does not move.** A mislabelled button that silently creates the wrong
entity.

### D-C2 — Quick-created records have no employee

The dialog never collects `user_id`. `AssessmentController::store` inserts
`user_id` **NULL** (line 88); `DevelopmentPlanController::store` reads
`user_id_target ?: user_id`, both absent (line 414); `CertificationController::store`
likewise (line 381).

The assessment then appears in the Assessment Workspace as a row named **"Unknown"**,
role `N/A`, campaign `—` — and **the title the user typed is never displayed**,
because `mapAssessment` does not return it.

### D-C3 — Renaming a competency orphans every role mapping

`s_user_skill_jobrole` stores the competency **title as a string**
(`RoleMappingController.php:205, 224-235`). `competencyLibraryUpdate` rewrites
`s_users_skills.title` (line 2577) and touches nothing else. The drawer's
Associations tab queries `where('skill', $skill->title)` (line 2354) and returns
zero.

**Rename a mapped competency and its mappings vanish from the UI while the orphan
rows remain in the database.** Same root cause as L-11.

### D-C4 — Write-only fields, and a drawer that shows less than the form collects

The create form collects **15** fields; the drawer's Basic Information block shows
**9**. **Sub Category** and **Department** are collected and displayed **nowhere**.
The Attachments tab renders 5 of the 8 "Evidence & Resources" fields — *Business
Link*, *Related Competencies* and *Tags* are stored and never rendered by any tab.

### D-C5 — The Proficiency tab cannot be fed by the form

The form's *Proficiency Scale* is free text into `s_users_skills.proficiency_level`
and surfaces only as `scale_label`. The actual level rows come from
`s_proficiency_levels` (line 2331-2340), **which this screen cannot write** — only
`StudioController` can. So a competency created here shows the tenant-wide scale or
*"No proficiency levels"*, whatever was typed.

Third appearance of D3 from the previous write-up: `s_proficiency_levels` is
writable from exactly one legacy surface.

### D-C6 — The dashboard counts the wrong queue

`CommandCenterService::workQueues` counts `s_competency_development_plans` where
`approval_status='pending_approval' AND approver_id=me` (lines 215-221). **It never
reads `s_competency_approvals`**, so competencies and frameworks awaiting review are
invisible on the dashboard that exists to surface them. Its click target is dead
anyway (§4).

### D-C7 — No LMS write path from either screen

The drawer's *Learning Assigned* tile only **counts** `lms_assignments` where
`source='competency'` (lines 2435-2440); those rows are written elsewhere. Neither
screen offers *"Assign Course"*, and *Learning Resources* is free text.

Per the ownership model, Competency hands a measured gap to LMS to build the skill.
**That handoff does not originate here.** Same gap as L-08, seen from the other end.

---

## 6. CONNECTIONS TO BUILD

Cost tiers and rules R-a / R-b / R-c as defined in
`competency-library-taxonomy.md` §5.

| # | Connection | From → To | Why it matters to a buyer | Cost | Blocked by | Evidence |
|---|---|---|---|---|---|---|
| **C-01** | Make `approve_status` **server-controlled** — reject any client-supplied value on create, edit and restore | within Competency | **Four independent bypasses** mean approval is decorative. A buyer's auditor will test exactly this | **S** | — | `skillLibraryController.php:2527, 2587-2589, 2222-2223` |
| **C-02** | On reject, return the subject to a resubmittable state | within Competency | A rejected competency is currently **stuck forever**, and the only escape is the bypass | **XS** | C-01 | `ApprovalController.php:316-332`; `cm-competency-library.tsx:1223` |
| **C-03** | Relabel or reimplement *"Create Role Mapping"* to call `PUT /competency/role-mapping/cell` | Command Center → Framework | A button that **silently creates the wrong entity**. Relabelling is XS; reimplementing is S | **XS–S** | — | `cm-command-center.tsx:56`; `routes/api.php:449` |
| **C-04** | Validate `competency_type` and `jobrole` in `CompetencyController::store` instead of dropping them | Command Center → Library | Users are entering data that vanishes with a success message | **XS** | C-05 (agree the vocabulary first) | `CompetencyController.php:81-87` |
| **C-05** | **One competency-type vocabulary** — the KASA set — spelled to match `s_skill_matrix.type` | Competency → measurement store | Definition and measurement currently **share no key**. Fixing the spelling is part of the fix, not a detail | **S** | `competency` table (§10 step 3) | `cm-command-center.tsx:129-135`; `cm-competency-library.tsx:87` |
| **C-06** | One status vocabulary across both creators | within Competency | *"Archived"* currently produces **Pending** | **XS** | C-05 | `CompetencyController.php:104` |
| **C-07** | Require an employee on quick-created assessments / plans / certifications | Command Center → Assessments | Records named **"Unknown"** with no subject are unusable and cannot be cleaned up | **S** | — | `AssessmentController.php:88`; `DevelopmentPlanController.php:414` |
| **C-08** | **One navigation helper**; delete both invented URL builders | across modules | Every cross-module link on two screens is dead, by two different mistakes | **M** | — | `use-content-map.ts:6-15`; `cm-competency-library.tsx:797` |
| **C-09** | Point the dashboard's approvals queue at `s_competency_approvals` | Command Center → Approvals | The dashboard that exists to surface pending work **cannot see it** | **XS** | — | `CommandCenterService.php:215-221` |
| **C-10** | Show the fields the form collects — Sub Category, Department, Business Link, Related Competencies, Tags | within Library | Users enter data they can never read back. R-c | **display** | — | `cm-competency-library.tsx:1394-1421` |
| **C-11** | Make the Audit & Activity Center reachable | navigation | It holds the **only** approve/reject UI, and may have no menu row | **XS** | verify against `tblmenumaster_g2g` | `content-map-m2.ts:17-20` |

### 6.1 New work versus already-approved work

| # | Verdict | Maps to |
|---|---|---|
| C-01, C-02 | **NEW** | approval integrity; no Gate B item covers it |
| C-03, C-04, C-06, C-09, C-10 | **NEW** | screen-level defects |
| C-05 | **ALREADY APPROVED** | Q-A2 (Competency = KASBA bundle) + `competency` table §2.1. This is its Library-side application |
| C-07 | **ALREADY APPROVED** | `02-domain-model.md` §11 (v) polymorphic integrity — a record with a NULL subject is the same defect class |
| C-08 | **NEW** | no navigation contract in Gate B. **Flagged: likely belongs to a cross-cutting write-up, not to Competency** |
| C-11 | **ALREADY APPROVED** | `01b-scope-triage.md` nav work + the G-NAV-01 fix template |
| D-C3 (no connection — covered) | **ALREADY APPROVED** | L-11 / §10 steps 12, 14. Not re-proposed |
| D-C7 (no connection — covered) | **ALREADY APPROVED** | L-08 → `course_competency_map`. Not re-proposed |

**Tally: 7 new, 4 already approved.** Two defects (D-C3, D-C7) are deliberately
**not** given connection IDs because L-11 and L-08 already cover them — the evidence
is recorded, the work is not counted twice.

### 6.2 Evidence for existing gaps — not new gaps

| Finding | Existing gap | Note |
|---|---|---|
| No role check anywhere in this unit (`ResolvesCompetencyContext.php:28-40` resolves only tenant + user; `skillLibraryController.php:1649-1668` checks only that a token exists) | **G-SEC-01** (S1) | Any authenticated tenant user can create, edit, archive, clone, bulk-import, export the whole library, submit for approval **and approve their own submission** |
| Department stored two incompatible ways | **G-LIB-01** | Second module, same root cause |
| `s_proficiency_levels` writable only from the legacy Blade surface | **G-LIB-07** | Third appearance |

**One new gap raised: `G-COMP-01` (S1)** — the approval workflow is optional in four
independent ways and rejection is a dead end. Distinct from G-SEC-01 because it
would survive a perfect RBAC fix.

---

## 7. Status

`Analysis Done`. No code changed. 78 rows, 0 structural errors, 4 of 4 security
negatives verified TRUE (one understated by the inventory and corrected upward in
§3.1), 11 connections of which **7 are new**.

Next in C2 order: **Framework & Role Mapping**.
