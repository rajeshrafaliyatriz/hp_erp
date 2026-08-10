# 01 — Inventory: what actually exists

> ⚠️ **SCOPE QUALIFIER (2026-08-07):** this figure describes the **Next.js sidebar**
> (`tblmenumaster_g2g`). It says nothing about the Blade UI, which has its own menu
> tree of 200 rows — 30 of them not present here. **No number needs re-deriving;
> the qualifier travels with it.** See `G-SCOPE-01`.


**Gate A deliverable 1 of 3.** Read-only analysis. No application code was changed.
Date: 2026-08-05

---

## 1. How this was produced

Everything below is derived from the codebase and the live database, not from the
brief. Each step is reproducible; the scripts and their raw output are in
`_evidence/`.

| Source | Method | Output |
|---|---|---|
| Navigation tree | `tblmenumaster_g2g` queried live, rendered as a tree | `_evidence/menu-tree.txt`, script `dump-menu.php` |
| Screen wiring | Nav tree joined against `hooks/content-map-m*.ts` + `use-content-map.ts` | `_evidence/nav-crossref.txt`, script `nav-crossref.py` |
| Backend surface | `routes/*.php` parsed; controllers and migrations counted on disk | inline below |
| Schema | `information_schema` on the configured database | inline below |
| Roles | `tbluserprofilemaster` + `tbluser` joined | §6 below |
| Feature elements | 15 parallel agents read every listed component in full | `_raw-inventory/` (3,378 elements — **unverified input**) |

**Three false findings were caught and corrected while building this**, which is
why the method is documented rather than just the conclusions:

1. The first nav dump truncated `access_link` at 60 characters. Since that column
   is the join key against the frontend, the truncation invented 40+ "broken
   navigation" entries that were correctly wired.
2. The cross-reference initially read `accessLink` only as a string literal. `M1`
   uses imported constants (`ORG_PROFILE_ACCESS_LINK`, …), so all six
   Organizational Management screens appeared broken when they are fine.
3. Raw menu `status` overstates reachability. A row with `status=1` under a
   parent with `status=0` is invisible. Effective visibility must walk the whole
   ancestor chain.

After all three corrections: **0 broken navigation entries.** Had any of them gone
unnoticed, this document would have opened with a fabricated crisis.

---

## 2. Codebase scale

| | Count |
|---|---:|
| Laravel controllers | 288 |
| Eloquent models | 199 |
| Migration files | 211 |
| Tables in the live database | **357** |
| API route declarations (`routes/api.php`) | 739 |
| Total registered routes (`php artisan route:list`) | 1,683 |
| Frontend page routes (`app/**/page.tsx`) | 27 |
| Frontend domain components | 197 |
| Navigation rows (`tblmenumaster_g2g`) | 188 |

Note the gap: **211 migrations vs 357 live tables.** The schema is substantially
larger than the migrations describe. This is the drift documented in
`../../FIX-PLAN-v2.md` (Phase 2) and it directly affects Phase 3 — see §5.4.

---

## 3. The real navigation tree

Nine top-level modules exist. The brief names five.

| # | Module | Menu id | Status | Sub-items | In brief's scope? |
|---|---|---:|---|---:|---|
| 1 | Organizational Management | 1 | Enabled | 25 | Yes (§5.1) |
| 2 | Competency Management | 2 | Enabled | 10 | Yes (§5.2) |
| 3 | Talent Management | 3 | Enabled | 11 | Yes (§5.3) |
| 4 | LMS | 4 | Enabled | 18 | Yes (§5.4) |
| 5 | Task Management | 204 | Enabled | 11 | Yes (§5.5) |
| 6 | HRIT Management | 5 | Enabled | 30 | Partially (§5.6) |
| 7 | **Agentic AI** | 186 | **Enabled** | 8 | **No** |
| 8 | **Reports** | — | Enabled | 30+ | **No** |
| 9 | **CRM** | 199 | Disabled | 3 | **No** |

### Reachability

| Category | Count | Meaning |
|---|---:|---|
| Wired and visible | **63** | User can click it and a screen renders |
| Container nodes | 16 | Expand a submenu; correctly have no screen |
| **Broken navigation** | **0** | Nothing visible is dead |
| Hidden by a disabled ancestor | **55** | `status=1` itself, but an ancestor is `status=0` |
| Disabled outright | 49 | `status=0` |
| External links | 2 | Leave the product entirely |

**The headline number: 63 working screens out of 188 navigation rows.** The other
125 are containers, disabled, or hidden behind something disabled.

### The two external links in navigation

| Menu | Target |
|---|---|
| Skills (id 24, disabled) | `https://form-scholar-clone.vercel.app/submit/9b13d496-…` |
| Pal (id 187, disabled) | `https://learningagent-…streamlit.app/` |

Both are third-party hosts embedded in the product's own menu. Both are currently
disabled. For a product sold to corporate customers this is a governance question,
not just a tidiness one — see `10-open-questions.md` **Q-A5**.

---

## 4. (a) In the brief's scope, NOT in the code

| Brief reference | Expectation | Reality |
|---|---|---|
| §5.1 Employee Directory → **Competency Mapping section** | "employee → job role → framework → required KASBA → current proficiency → gap → development plan" | **Does not exist.** `employee-directory.tsx` is 363 lines. It carries a `skills: []` array populated from `/user/add_user` (line 131) and one KPI, "Skill Deficit" (line 217), which counts employees where `skills` is empty **or** `profile_name` is missing. No job role, no framework, no proficiency, no gap, no plan. This is the single biggest gap relative to the brief |
| §7 Role: **Department Head / Reporting Manager** | Approves plans, rates reports, owns team view | **No such role exists.** See §6 |
| §7 Roles: Super Admin, Instructor, Assessor, Recruiter, Auditor | Distinct personas | **None exist.** See §6 |
| §5.2 "Skill Gap Analysis" | Implied by golden threads 1 and 4 | Exists as menu id 26 **disabled**, with no component behind it |
| §6 Golden thread 2 — task → competency signal | Task failure raises a competency flag | No mechanism exists. `app/Events`, `app/Listeners`, `app/Observers` are absent from the codebase entirely |

## 5. (b) In the code, NOT in the brief's scope

| Module / feature | Size | Note |
|---|---|---|
| **Agentic AI** | 8 screens, enabled, ~13 API routes (`/agents`, `/runs`, `/workflows`) | Fully wired: `use-content-map.ts` registers `'186' → m7`. Needs an explicit in/out decision |
| **Reports** | 30+ screens | Almost entirely disabled. Overlaps Task "Reports & Analysis" and LMS reporting — a duplication risk |
| **CRM** | Marketing, Leads, Master Fields | Disabled. Unrelated to HR. Likely belongs to the other product in this monolith |
| **HRIT: Payroll** | 7 screens, enabled | Real and working. The brief only says "inventory it" |
| **HRIT: Attendance, Leave** | 11 screens, enabled | Real and working. **Relevant to Phase 3** — attendance and leave are inputs a credible performance review needs |
| Career Explorer, Skill Assessment, Assessment Library (LMS) | 7 screens | All hidden behind disabled parents. "Self skill rating" and "Assessment List" overlap Competency's Assessments — see §5.3 |

### 5.3 Corrections to earlier audit findings

Two findings carried in `FIX-PLAN-v2.md` are **now wrong** and must not be acted on:

- **F-19 "Agentic module unreachable"** — `use-content-map.ts:14` registers
  `'186': () => import('./content-map-m7')`. The module is reachable. Finding is stale.
- The same file's claim that `COMING_SOON_CONTENT` gates Compensation still holds,
  but Compensation is `status=0` in the nav, so it is unreachable for a second,
  independent reason.

### 5.4 Schema drift that blocks Phase 3

Verified live. These have migrations **recorded as run**, but the tables do not exist:

| Missing table | What it breaks |
|---|---|
| `competency_evidence` | The evidence repository the Competency spec lists as a shared feature. Breaks `EmployeeCompetencyProfileController`, `CertificationController`, `skillLibraryController` |
| `competency_certification_requirements` | **This is why Certifications connects to nothing.** Breaks `CertificationController`, `CertificationRequirementController`, and its model |
| `s_skill_jobrole` | `App\Models\libraries\skillJobroleMap` is dead |

Any Phase 3 connection that depends on evidence or certification requirements is
blocked until these are restored.

## 6. (c) Duplicate implementations of the same concept

This is the section that most affects the design, because each row is a candidate
single-source-of-truth violation.

| # | Concept | Implementation A | Implementation B | Assessment |
|---|---|---|---|---|
| D1 | **Job role** | `s_user_jobrole` (4,610 rows, tenant-scoped) — mastered in Organization | Competency → Library & Taxonomy "Job Role" tab writes the same table | Two screens, one table. **Needs an owner decision — Q-A1** |
| D2 | **Skill / competency** | Competency Library (`s_users_skills`, 3,976 rows) | Library & Taxonomy "Skill" tab — `LIBRARY_TABS[0]` is `SKILL_LIBRARY_CONFIG` | **Real duplication.** `cm-libraries-taxonomy.tsx:20-24` claims skills are "deliberately NOT a tab here… Two screens writing one table is how a partial edit blanks a column the other form never showed" — but the tab strip renders `LIBRARY_TABS` unfiltered. **The comment is stale and the risk it describes is live** |
| D3 | **Skill taxonomy editing** | `cm-skill-taxonomy.tsx` (321 lines, own menu id 41) | Competency Library toolbar hosts a taxonomy editor | Customer's removal hint is plausible. **Impact check required before any removal** |
| D4 | **Course** | `sub_std_map` (96 rows) — a K-12 subject↔standard table reused for HRMS | `create-course-page.tsx` (1,151 lines) vs `course-form-sheet.tsx` | Two creation paths into one table with different field sets |
| D5 | **Certificate** | LMS `lms_certificates` | Competency → Certifications | Two stores of one concept; the Competency side's requirements table is missing (§5.4) |
| D6 | **Assessment** | Competency → Assessments | LMS → Assessment Library + Skill Assessment (both hidden) | Overlapping, one side disabled |
| D7 | **Reporting** | Task → Reports & Analysis | Top-level Reports module (30+ screens) | Overlap |
| D8 | **Onboarding** | Talent → Onboarding (enabled, `/api/onboarding/*`) | Talent → "Talent Onboarding" + "Employee Onboarding" (both disabled) | Three entries, one live |
| D9 | **Compliance library** | Organization → Compliance & Discipline (enabled) | HRIT → Compliance Management (disabled) | Duplicate |
| D10 | **Attendance report** | HRIT → Attendance Reports (enabled) | HRIT → "Attendance Report" (id 162, disabled) + 3 HRMS Report screens | Duplicate |

### D11 — A live routing bug

`_evidence/nav-crossref.txt`, "DUPLICATE access_link":

| Menu | id | `access_link` |
|---|---:|---|
| Priority Management | 218 | `/module/task-management/administration/task-priority` |
| **Permision** | **219** | `/module/task-management/administration/task-priority` |

Task Management → **Permission** points at the **Priority Management** screen. The
Permission screen (`tm-permissions.tsx`) is built but unreachable. This is a
one-row data fix, and it explains the brief's report that Permission does not work.

---

## 7. The role model — the biggest structural finding

The brief (§7) expects up to nine roles. The database has **three**.

| Profile | Users | Present in |
|---|---:|---|
| Employee | 238 | every tenant |
| Admin | 76 | every tenant |
| HR | 72 | every tenant |
| `ZZ Audit Role v2` | 0 | tenant 3 only — a test artefact |

Every tenant is seeded with exactly Admin / HR / Employee.

**There is no Department Head, Reporting Manager, Instructor, Assessor, Recruiter,
Auditor or Super Admin.** This matters more than any single missing screen:

- All five supplied PDFs treat **Department Head** as a primary persona with
  approval rights ("Sign-off on individual development plans", "Approve/reject
  team members' enrolment", "Approve completed critical-path tasks").
- Golden threads 2, 4 and 5 (§6 of the brief) all require a manager in the loop.
- `03-rbac-matrix.md` cannot be written against roles that do not exist.

There is also no reporting-line field in use for scoping: data scope today is
tenant-wide or self, with nothing in between. "Own / team / department / org"
scoping as required by §7 has no `manager_id` chain to resolve *team* against.

**Consequence for work already done:** the `profile:admin,hr,manager` middleware
added in Phase 1 gates on a `manager` profile that never matches. The gates are
effectively admin+HR. Not a security hole — the gate is closed, not open — but the
intent is unmet and it should be corrected when the role model is settled.

---

## 8. Module-by-module screen inventory

Only **enabled and wired** screens are listed. Full tree in `_evidence/menu-tree.txt`.

### 8.1 Organizational Management — 6 screens
| Screen | Menu id | Component |
|---|---:|---|
| Organization Profile | 12 | `organization/organization-information.tsx` |
| Department Management | 13 | `organization/department-management/department-list.tsx` |
| Employee Directory | 22 | `organization/employee-directory.tsx` |
| Role & Permissions | 23 | `organization/role-permissions.tsx` |
| Compliance Library | 206 | `hrms/compliance-discipline/compliance-library-management.tsx` |
| Disciplinary Library | 207 | `hrms/compliance-discipline/disciplinary-management.tsx` |

Disabled here: Organization Dashboard, Admin & Configuration, Group-wise rights,
Individual rights, Search Employees by Skills, Task Assignment & Progress, 3×
Suggestion screens, 5× Communication Tools, Template Management, Complaint Mgmt,
Skills, Certifications, **Skill Gap Analysis**.

### 8.2 Competency Management — 10 screens
| Screen | Menu id | Component | Lines |
|---|---:|---|---:|
| Competency Library | 34 | `cm-competency-library.tsx` | 1,776 |
| Command Center | 37 | `cm-command-center.tsx` | 636 |
| Skill Taxonomy | 41 | `cm-skill-taxonomy.tsx` | 321 |
| Taxonomy Ontology | 43 | `cm-taxonomy-ontology.tsx` | 152 |
| Framework & Role Mapping | 154 | `cm-framework-mapping.tsx` | 1,206 |
| Assessments | 155 | `cm-assessment-workspace.tsx` | 674 |
| Employee Profiles | 156 | `cm-employee-profiles.tsx` | 980 |
| Development & Career Paths | 157 | `cm-development-career.tsx` | 2,604 |
| Certifications | 158 | `cm-certifications.tsx` | 2,008 |
| Library & Taxonomy | 223 | `cm-libraries-taxonomy.tsx` → `libraries/*` | 92 + tabs |

Also mapped but **not in the nav**: `cm-audit.tsx` (1,215 lines) is registered on
`submenuId: '208'`; no menu row 208 exists — an unreachable built screen.

Largest API surface in the product: **166 `/competency/*` routes.**

### 8.3 Talent Management — 7 screens
Talent Dashboard (46), Recruitment (47), Onboarding (48), Performance Reviews &
Appraisals (49), Mobility & Succession (52), Offboarding (171), Administration (178).
Disabled: Compensation (50), HR Template Engine (198), Talent Onboarding (180),
Talent Onboarding Dashboard (179).
API: 53 `/talent/*` + 61 `/performance/*` + 32 `/onboarding/*`.

### 8.4 LMS — 8 screens
Learning Dashboard (80), Learning Catalog (182), My Learning (209), Assignments (81),
Sessions & Calendar (82), Certifications & Records (83), Course Builder (84),
Administration & Governance (85).
Disabled: Assessment Library, Skill Assessment (Search skill, Self skill rating),
Career Explorer (4 screens). API: 77 `/lms/*`.

### 8.5 Task Management — 11 screens
Dashboard (210), My Tasks (211), Projects & Workstreams (212), Dependencies &
Workstreams (213), Task Calendar (214), Reports & Analysis (215), and under
Administration (222, no link): Status (217), Priority (218), **Permission (219 —
mislinked, D11)**, Integration (220), Audit Log (221).
This is the only module with server-side role enforcement (`task.permission`).

### 8.6 HRIT Management — 12 screens
Attendance Tracking (100), Attendance Reports (101); Leave Dashboard (102),
Requests (103), Reports (104), Configuration (165); Payroll Type (105), Salary
Structure (106), Deduction (108), Form 16 (109), Salary Certificate (110), Monthly
Payroll Report (140).

### 8.7 Agentic AI — 8 screens
Agent Dashboard, Agentic Library, Create Agent, Run Log, Analytics, Multi-Agent,
Reflection, Agent Workspace. Enabled and wired.

---

## 9. Orphaned navigation rows

Six rows point at a `parent_id` that does not exist, so they can never render:

| id | Name | Dangling parent |
|---:|---|---:|
| 44 | Peer-to-peer skill endorsements | 35 |
| 45 | Role-based competency frameworks | 36 |
| 71 | Internal & External Talent Database | 51 |
| 72 | AI-Based Recommendations | 51 |
| 175 | Skill Ontology | 173 |
| 177 | Competency Dashboard | 176 |

Rows 44, 45, 175 and 177 describe capabilities the brief asks for. They are
evidence of an earlier, abandoned information architecture rather than working
features.

---

## 10. What this means for Phase 3

Stated plainly, before any design work:

1. **The product is not six isolated modules — it is 63 working screens across nine
   modules, with 125 nav rows switched off or hidden.** Deciding what is in the
   product is a prerequisite to connecting it.
2. **The connective tissue is absent by construction.** No events, listeners,
   observers or jobs exist. Every cross-module flow in the brief's §6 needs a
   mechanism that has not been built yet — this is an architecture decision, not
   a wiring task.
3. **Three roles cannot express the brief's flows.** Department Head is required by
   every golden thread involving approval. The role model must be settled before
   `03-rbac-matrix.md` or `04-user-flows/*` can be written honestly.
4. **Ten duplicate-concept pairs (D1–D10) must be resolved before connecting
   anything**, or the connections will be built onto whichever copy happened to be
   picked, and the other copy will silently drift.
5. **The Employee Directory competency mapping — the brief's stated biggest
   confusion — does not exist at all.** It is not broken; it was never built.

---

## 11. Verification status

| Claim | Method | Status |
|---|---|---|
| Nav tree, 188 rows, statuses, orphans | Live query, `dump-menu.php` | **Verified** |
| 0 broken nav / 63 wired | `nav-crossref.py` after three corrections | **Verified** |
| Permission↔Priority mislink (D11) | Duplicate `access_link` in live data | **Verified** |
| 3 roles only | `tbluserprofilemaster` + `tbluser` join | **Verified** |
| 3 tables missing | `information_schema` | **Verified** |
| Agentic registered (F-19 stale) | `use-content-map.ts:14` | **Verified** |
| No Competency Mapping in Employee Directory | Full read of the component | **Verified** |
| Skill tab duplication (D2) | `library-config.ts:190-191` | **Verified** |
| Counts: controllers/models/migrations/routes | Filesystem + parser | **Verified** |
| 3,378 catalogued feature elements | Agent output | **Unverified input** — re-checked per element in Gate C |
