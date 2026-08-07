# Phase 3 Progress

Last updated: 2026-08-05
Current gate: **C (audit) and D (foundation build) RUNNING IN PARALLEL**
Gate B: **SIGNED OFF** 2026-08-05 (conditional on B1, applied)
Gate A: **SIGNED OFF** 2026-08-05

---

## PLAN CHANGE 2026-08-06 — C17 / C18

**Two threads now run in parallel.** Progress on each is reported separately.

| Thread | What |
|---|---|
| **D — foundation build** | Gate D opened early (**C17**). The foundation items are fully specified in Gate B and depend on **none** of the remaining 30 sub-module audits, so waiting bought nothing. Six-step order below |
| **C — audit** | Compressed to ~5 turns (**C18**). The sweeps *are* the deep pass |

### C18 — the trade-off, recorded explicitly so it is never mistaken for an oversight

> **DECIDED, EYES OPEN: the per-element coverage ledger (C11) is ABANDONED.**
> We will **not** be able to say that each of the 3,378 catalogued elements was
> individually examined.

**What is given up:** per-element attestation.
**What is kept, and is worth more to a customer:** every known defect pattern
checked across the **whole codebase in one pass**, plus the golden-thread modules
read by hand.

**The evidence for the trade:** across two full write-ups, exhaustive enumeration
produced **zero findings on its own**. Every confirmed break came from reading a
form config or a controller, or from a sweep. C11 would have priced a guarantee
nobody was buying.

**This was a deliberate decision, not a shortcut that was later rationalised.**

### C18 — structure and token rules

**One write-up per MODULE, not per sub-module — six files:**
`competency.md` (9 sub-modules), `talent.md` (7), `organization.md` (5),
`lms.md` (3), `task.md` (2), `other.md` (4 — HRIT, Agentic, Reports, CRM).

Each contains **only**: sweep hits for its screens · a hand-read of its primary
config + controller · the §5.1 reconciliation · CONNECTIONS TO BUILD.
No narrative, no "what this screen is", no restating Gate B.

| # | Token rule |
|---:|---|
| 1 | **The raw inventory JSON is a lookup, not reading material.** Zero findings across two write-ups |
| 2 | **Read by pattern, not by file.** Grep the sweep patterns, open hit regions. Never read a 6,000-line component end to end |
| 3 | **Output deltas only.** If it is written down, reference it |
| 4 | **100% verification for negative and trust/security claims only.** Sample the rest at 10% |

### R6 — standing rule

> **A sweep produces CANDIDATES, never FINDINGS.** Nothing from a sweep enters a
> write-up or the gap register until it is hand-verified.

Earned eight times over (R4 tally). **Stop condition:** if compression starts
producing shallow or unreliable output, **stop and say so** rather than continue —
spending the turns is cheaper than a plan built on weak findings.

### New labels registered this round

| Label | Meaning |
|---|---|
| **G-SEC-08** | Server must own `verification_status` — `CertificationController.php:390` takes it from the client. **A credential that verifies itself.** Candidate from S-3, verification pending |
| **C21/C22** | ✅ **C21: 1,463 routes across all 7 route files; 77 controllers resolve tenant/user from the request, 35 of them via `api.php`. ONE verified (`skillLibraryController`), 76 candidates — R6. C22: Phase 1's sweep counted `findToken()` as a guard, so a controller that validates a token and discards its owner passed it.** Evidence: `_evidence/sweeps/c21-c22-tenant-enumeration.md` |
| **C15** | ✅ **ANSWERED — OUTSIDE, and it is a security finding.** `skillLibraryController` (12 API routes) discards the token's owner and takes tenant + user from the request. **Live cross-tenant read/write on the Competency Library.** → **G-SEC-09 (S1)**. Evidence: `_evidence/sweeps/c15-tenant-field.md` |
| **R6** | Above |
| **R8** | **PRE-DELETION CHECKLIST** — exports, referrers, where each moves, what is orphaned, what changes outside scope. Earned by L-03R: `dash()` had 4 live call sites in the file being deleted | see `07-gap-register.md` |
| **C20** | Verification protocol **PROPOSED** — `11-verification-protocol.md`. I **cannot** start the app or click a UI, so I can never mark my own work `Verified`. Cap of 3 `Built-unverified`; **currently 2** |
| **R7** | **A cost estimate must NAME THE FILES it touches, in both repos.** An estimate that does not is a guess. Applied retroactively — `09-implementation-log.md` |
| **C19** | Build the picker mechanism ONCE, generically. Then every entity binding is configuration |
| **§10.0** | **BINDING RULE**, `02-domain-model.md`: entity → closed picker + permission-gated inline create; vocabulary → open choice. Decided by **ownership**, not drift. Pre-answers L-04, L-07, L-08, L-09 and every field L-11 touches |

---

## ✅ C23 GUARD LIVE — **48 EXECUTED FAILURES** · `_evidence/sweeps/c23-worklist.md`

912 GET routes, all six route files, run in-process against the real database.

| FAIL | PASS | VACUOUS | UNTESTABLE |
|---:|---:|---:|---:|
| **48** across **30 controllers** | 321 | 89 | 454 |

**48 is a FLOOR.** 454 routes — half the read surface — could not be called, and
the 864 write routes are untested. **PayrollController leaks on 9 routes**;
`api/skills` returns **297,582 bytes for another tenant vs 84,363 for its own**.

**R1 satisfied:** static source reading and dynamic execution agree on both
G-SEC-09 and G-SEC-10. No longer inference.

**The guard is now the completion criterion — "green" replaces "we think we got
them all".**

## ⚠️ C27 — PayrollController VERIFIED · **G-SEC-10 (S1)**

Imports the trait, **never calls it**, reads tenant from the request at ~18 sites,
and has an explicit `if ($type == 'API')` branch that hands API callers their own
tenant. **39 routes, salary data, token-reachable.** The trait's presence is what
made it look done — the same illusion as C22, now confirmed in application code.

## ⛔ C25 — NO SECURITY FIGURE IS QUOTED UNTIL RECONCILED

`_evidence/sweeps/c25-security-reconciliation.md` — **done**. Headlines:

- **1,676 routes across six files**, not 739. `api.php` is **48%** of the surface.
- **THE BLADE ASSUMPTION WAS FALSE.** `authMiddleware` accepts a session **OR a bare Sanctum token**, so `lms/hrms/user/settings` are token-reachable. **Scope 35 → 66 controllers.** `PayrollController` — **salary data, has the trait** — is now in scope.
- **G-SEC-01, G-SEC-04 and the 279 remain UNQUOTABLE.** Their proxy fails both ways; only the C23 guard can replace them.
- **G-SEC-02 survives intact** — the one Phase 1 security number whose proxy has no gap.

## ⛔ C24 — COMPANY-LEVEL RELEASE PRECONDITION

> **NO CUSTOMER TENANT IS CREATED ON THIS PLATFORM UNTIL A TENANT-ISOLATION TEST
> SUITE PASSES END TO END.**

**This is a business rule, not an engineering task.** It is recorded here so it
cannot be traded away under delivery pressure by someone who was not in this
conversation.

Reason: **G-SEC-09** — a valid token from any tenant can read and write any other
tenant's competency library. It is the one finding that must not be discovered by a
client. It survived a phase dedicated to finding it (**C22**), which is why the
gate is a *passing test suite*, not a *completed fix*.

---

## GATE D — foundation build thread

Nothing in this thread is customer-visible; all of it blocks every connection.

| # | Item | Status |
|---:|---|---|
| 1 | **L-03R** — delete the dead panel | ✅ **BUILT** — `09-implementation-log.md` D-001. 2 files, −404 lines, tsc clean. **Not yet `Verified`**: AT-L03R steps 1/4 need a running app |
| 2 | **G-COMP-01 + G-SEC-08 + the rejected-competency dead end** — server owns `approve_status` and `verification_status` | ✅ **BUILT** — D-002. 7 sites across 4 files. ⚠️ **Visible behaviour change**: new competencies are born `Pending`, so they will not appear in the matrix/framework until approved. **Raises the priority of C-11** (the only approve/reject UI may be unreachable) |
| **3** | **G-SEC-09 — migrate `competencyLibraryContext` onto `ResolvesApiIdentity`** | ✅ **DONE 2026-08-06 (D-003).** Guard **2 FAIL → 0**. `API-verified-UI-pending`. Original note: Adoption of a proven trait, not design. **Preconditions:** (a) **R9** — read every frontend consumer of the 12 routes before and after; a tenant resolved from the token where the client supplied it will silently change which rows a screen returns. (b) confirm no legitimate cross-tenant use (global library import / super-admin) — if one exists it needs an explicit permissioned route, not to be silently broken |
| **3a** | **C23 — THE GUARD, WRITTEN FIRST** (inverted). It encodes the property directly: every token-reachable route resolves tenant and actor from the token, never from the request body. **Its failure list IS the worklist** — mechanical, objective, completion criterion is "green", not "we think we got them all". **Now the gating artefact for FOUR figures** (G-SEC-01, G-SEC-04, the 279, G-SEC-09 scope), not one | **NEXT** |
| ~~3b~~ | ~~regression guard built WITH the fix~~ — superseded by 3a: a test asserting every API route resolves tenant from the token, failing CI otherwise. Same shape as the G-SEC-02 route-declaration guard. **Without it this regrows the next time a controller is added** | With item 3 |
| 4 | **C19 — the picker mechanism, built once**: id-bearing meta bucket `{bucket,id,label}`, `LibraryMeta` carrying ids, ONE closed-picker control with the gated create action, generic payload mapping | Not started. **M–L** |
| 5 | **L-01, L-02** as **configuration** on top of C19 | Blocked on 3. Returns to **XS** once C19 exists |
| 6 | Join tables + `certification_type` + `certification_competency_map` + the 3 restored tables, as ONE migration (§10) | Not started |
| 7 | `tbluser.reporting_manager_id` + `hrms_departments.head_user_id`, **with cycle validation** | Not started |
| 8 | Tri-state rights columns, then **populate** the matrix (§3.1–3.7) **with a before/after menu diff for review** | Not started |
| 9 | Event store + projector/reactor split + `task_status_history` | Not started |
| 2 | **G-COMP-01** + **G-SEC-08** — server owns `approve_status` and `verification_status` | Not started |
| 3 | The 5 join tables + `certification_type` + `certification_competency_map` + the 3 restored tables, as **ONE** migration (§10) | Not started |
| 4 | `tbluser.reporting_manager_id` + `hrms_departments.head_user_id`, with **cycle validation** | Not started |
| 5 | Tri-state rights columns, then **populate** the matrix from `03-rbac-matrix.md` §3.1–3.7, with a **before/after menu diff for review** | Not started |
| 6 | Event store + projector/reactor split + `task_status_history` | Not started |

---

## CURRENTLY WORKING ON

**Gate C — feature audit.** Gate B fully signed off (B1/B2/B3 applied, Q-F1
answered). **Both calibrations complete and both clean:**

| | Unit | Source shape | Rows | Real errors |
|---|---|---|---:|---:|
| **C1** | Competency → Library & Taxonomy | declarative config | 159 | **0** |
| **C1b** | Competency → Development & Career Path | 2,604-line imperative component | 206 | **0** |

Two points at opposite ends of the difficulty range, both zero. **Re-derivation is
not triggered** (C1 step 5, threshold 10%). C1's caveat that the easy unit might not
represent the hard ones was tested by C1b and did not hold — retired.

Every failure either calibration reported was the **checker's**, five times out of
five. That is now standing rule **R4** in `07-gap-register.md`.

**Write-up 1 of N delivered:** `06-feature-audit/competency-library-taxonomy.md`.
It establishes the **C8 fixed CONNECTIONS TO BUILD format** — columns
`# | Connection | From → To | Why it matters to a buyer | Cost | Blocked by |
Evidence`, ordered by descending value ÷ cost. Every subsequent write-up uses it
unchanged.

Also applied: **C6** (100% verification of every negative claim), **C6b** (each such
claim checked against the **prior decisions** as well as the code — added because
**L-14 passed the code check and still contradicted a verified Gate B finding**), and
**C7** (replay operating procedure, `05-data-flow-contracts.md` §6.2).

**Every write-up from Competency Library onward carries a `New work versus
already-approved work` table** (§5.1 shape), so Gate D cannot count the same work
twice.

**L-09 resolved → confirmed Gate B omission**, and it uncovered a larger finding:
**there is no certification TYPE entity** (`G-CERT-01`, S2). `certification_type` +
`certification_competency_map` added to the migration sequence as steps **3b/9b**
(`02-domain-model.md` §10.1).

**C9 delivered** — `06-feature-audit/00-pace-c9.md`. **2 of 32 sub-modules,
254 of 3,378 elements (7.5%), 4 turns, 2.0 turns per write-up.** Gate C will run
long: **45–70 turns at full depth.** Recommended trim is by **depth, not by
dropping sub-modules** — 14 FULL / 16 SHALLOW ≈ 40 turns. **Awaiting approval of
the split;** continuing at FULL depth in C2 order meanwhile.

**C10 IN PROGRESS — method changed to cross-cutting sweeps first.**
`_evidence/sweeps/00-sweep-status.md`. **4 of 7 sweeps run, 1 fully verified,
3 not started.** S-4b verified: **9 → 1** after two checker bugs were found by
hand-check; the one survivor is the known dead panel, so **that pattern is not
systemic**. S-3 (184 raw) and S-6 (27 tables) are RAW and must not be quoted.
**C11 coverage ledger and C12 re-projection follow the sweeps.**

Next 3 steps, in C2 golden-thread order:
1. `06-feature-audit/competency-framework-mapping.md` — Framework & Role Mapping
2. `06-feature-audit/competency-employee-profiles.md` — Employee Profiles
3. `06-feature-audit/competency-assessments.md` — Assessments

Cadence per C3: one sub-module = one write-up = one progress update. Never batched.

New gaps are appended to `07-gap-register.md` as one line and written up at Gate C.

---

## Status legend

`Not Started` | `In Progress` | `Analysis Done` | `Connection Implemented` | `Verified`

`Verified` requires a stated verification method (endpoint called, migration checked,
table queried, UI path walked). Analysis alone is **never** `Verified`.

---

## Gate checklist

| Gate | Artefact | Status | Notes |
|---|---|---|---|
| A | `01-inventory.md` | ✅ **APPROVED** | Signed off 2026-08-05 |
| A | `01b-scope-triage.md` | ✅ **APPROVED** | Signed off 2026-08-05, with 4 amendments applied |
| B | `03-rbac-matrix.md` | ✅ **APPROVED** | Signed off 2026-08-05, with 7 amendments (A1–A7) applied and Q-D1/D2/D3 recorded |
| C | `07-gap-register.md` | ✅ **ACCEPTED** | Numbers independently reconciled by Triz; C1/C2 corrected; G-SEC-04/05/06 added |
| B | `02-domain-model.md` | ✅ **APPROVED** | Signed off 2026-08-05 with Corrections 1–5 applied; Q-E1 and Q-E2 both answered |
| B | `04-user-flows/employee.md` | ✅ **APPROVED** | Signed off 2026-08-05 |
| B | `04-user-flows/manager.md` | ✅ **APPROVED** | Signed off 2026-08-05, M1–M5 applied |
| B | `04-user-flows/` remaining roles | Not Started | hr, admin, executive, auditor, recruiter, instructor-assessor |
| B | `05-data-flow-contracts.md` | **Analysis Done** | S6 one-source model, event catalogue with the named-consumer test, notification service, readiness gates, `task_status_history` first. **Q-F1 raised** |
| C | `06-feature-audit/00-calibration.md` | **Analysis Done** | **C1: 0 of 159. C1b: 0 of 206** (hard unit). Spot-check regime approved; **C6b** added — negative claims checked against code **and** prior decisions |
| C | `06-feature-audit/*` write-ups | **In Progress** | **2 of 32 delivered.** C2 order: Competency → Organization → LMS → Task → Talent → rest |
| C | `06-feature-audit/00-pace-c9.md` | **Awaiting decision** | C9 pace report. FULL/SHALLOW split proposed |
| D | `08-connection-plan.md` | Not Started | |
| D | `09-implementation-log.md` | **Current** | **D-001 built** (L-03R). R7 retroactive cost review recorded here |
| — | `10-open-questions.md` | **Current** | Single register; **24 questions — all answered.** Q-L1/L2/L3 closed 2026-08-06 |

---

## Module checklist

Expanded to sub-module level from the **actual** navigation tree
(`_evidence/menu-tree.txt`), not from the brief. Feature-level rows are added as
each sub-module is audited in Gate C.

| Module | Sub-module | Status | Detail lives in | Notes |
|---|---|---|---|---|
| Organizational Mgmt | Organization Profile | Analysis Done (raw) | `_raw-inventory/organization-hrit-*.json` | |
| Organizational Mgmt | Department Management | Analysis Done (raw) | same | |
| Organizational Mgmt | Employee Directory | Analysis Done (raw) | same | **Competency Mapping section is the key unknown — §5.1 of brief** |
| Organizational Mgmt | Role & Permissions | Analysis Done (raw) | same | Feeds Gate A `03-rbac-matrix.md` |
| Organizational Mgmt | Compliance / Disciplinary Library | Analysis Done (raw) | same | |
| Competency | Command Center | Analysis Done (raw) | `_raw-inventory/competency-command-center-*.json` | |
| Competency | **Competency Library** | **Analysis Done (audited)** | **`06-feature-audit/competency-library.md`** | **78 rows. 4 of 4 security negatives TRUE (one understated — the client supplies `approve_status`). 11 connections C-01…C-11, 7 new. New gap **G-COMP-01** (S1): approval optional 4 ways, rejection a dead end** |
| Competency | **Library & Taxonomy** | **Analysis Done (audited)** | **`06-feature-audit/competency-library-taxonomy.md`** | **176 rows in scope. C1 calibration unit, 0 errors. 8 defects D1–D8, **23 connections L-01–L-23** (13 new, 5 already approved, 2 bind to existing fields, 3 other). Q-L1 marked: BIND 10 / NOTE 13 / SUBSTITUTE 2, folded in as L-15…L-23. D1 is a confirmed break; **L-01/L-02 confirmed as the first Gate D commits, acceptance tests written in §5.4. L-03 WITHDRAWN → L-03R (delete the panel; the popup is richer) — needs deletion approval.** **L-14 corrected** — the catalogue lacks the link, the instance has it (67%); already approved as Q-C3** |
| Competency | Development & Career Path | Analysis Done (raw) | `_raw-inventory/competency-management-development-*.json` | 206 rows. **C1b hard-unit calibration, 0 errors.** Write-up pending its turn in C2 order |
| Competency | Skill Taxonomy | Analysis Done (raw) | same | Removal candidate — impact check required, **do not delete** |
| Competency | Taxonomy Ontology | Analysis Done (raw) | same | |
| Competency | Framework & Role Mapping | Analysis Done (raw) | same | |
| Competency | Assessments | Analysis Done (raw) | `_raw-inventory/competency-management-unit-cm-assess-*.json` | 257 elements |
| Competency | Employee Profiles | Analysis Done (raw) | same | |
| Competency | Certifications | Analysis Done (raw) | same | **Backing table missing — see G-A-04** |
| Competency | Development & Career Paths | Analysis Done (raw) | `_raw-inventory/competency-management-development-career-*.json` | |
| Talent | Talent Dashboard | Analysis Done (raw) | `_raw-inventory/tm-dash-admin-*.json` | |
| Talent | Recruitment | Analysis Done (raw) | `_raw-inventory/tm-recruitment-*.json` | 242 elements |
| Talent | Onboarding | Analysis Done (raw) | `_raw-inventory/talent-management-onboarding-*.json` | 222 elements |
| Talent | Performance Reviews & Appraisals | Analysis Done (raw) | `_raw-inventory/talent-management-performance-*.json` | 277 elements |
| Talent | Mobility & Succession | Analysis Done (raw) | `_raw-inventory/talent-internal-mobility-*.json` | 331 elements |
| Talent | Offboarding | Analysis Done (raw) | same | |
| Talent | Administration | Analysis Done (raw) | `_raw-inventory/tm-dash-admin-*.json` | |
| LMS | Learning Dashboard / Catalog / My Learning | Analysis Done (raw) | `_raw-inventory/lms-learning-dashboard-*.json` | |
| LMS | Assignments / Course Builder / Delivery | Analysis Done (raw) | `_raw-inventory/lms-build-assign-deliver-*.json` | 230 elements |
| LMS | Sessions / Records / Governance | Analysis Done (raw) | `_raw-inventory/lms-sessions-calendar-*.json` | 263 elements |
| Task | Dashboard / My Tasks / Projects / Dependencies / Calendar | Analysis Done (raw) | `_raw-inventory/task-management-task-core-*.json` | 240 elements |
| Task | Reports & Analysis + Administration | Analysis Done (raw) | `_raw-inventory/task-management-reports-*.json` | |
| HRIT | Attendance / Leave / Payroll | Not Started | — | In scope for inventory; see §7 of `01-inventory.md` for scope recommendation |
| Agentic AI | 8 screens | Not Started | — | **In code, not in the brief's scope list** |
| CRM | Marketing / Leads / Master Fields | Not Started | — | **In code, not in the brief's scope list**; currently disabled |
| Reports | 30+ report screens | Not Started | — | **In code, not in the brief's scope list**; mostly disabled |

---

## Decisions made

| Date | Ref | Decision |
|---|---|---|
| 2026-08-05 | — | Inventory is generated from `tblmenumaster_g2g` (the live nav tree) cross-referenced against the frontend content maps — not from the brief's scope list. The brief is treated as a hint per §2.2. |
| 2026-08-05 | — | The 3,378-element raw agent inventory is kept in `_raw-inventory/` as **unverified input**, not a deliverable. Every row is re-checked by hand before entering `06-feature-audit/`. |
| 2026-08-05 | **Q-A1** | **Organization owns JobRole identity** (code, title, department, grade, reporting line). Competency owns the **capability definition** only. Identity fields become read-only in Competency → Library & Taxonomy → Job Role tab, enforced **server-side**. One table, no duplicate. |
| 2026-08-05 | **Q-A2** | **Competency = a named bundle of KASBA items. Skill is one of the five KASBA dimensions, not a synonym.** Library & Taxonomy, Competency Library and Command Center forms all restructure around this. |
| 2026-08-05 | **Q-A3** | Non-live nav rows triaged in one pass → `01b-scope-triage.md`: **12 SHIP, 27 DEFER, 65 DELETE, 1 HOLD**. Flows are designed only for SHIP. Awaiting single-pass approval. |
| 2026-08-05 | **Q-A4** | **Agentic AI = IN** (live and reachable). **Reports = IN as a consolidation decision only** — one reporting home reading all modules, not per-module report screens. **CRM = DEFERRED, not deleted** — code and data intact, hidden from nav, no Phase 3 work. |
| 2026-08-05 | **Q-A5 (partial)** | **"Pal" (id 187) — remove from navigation.** **"Skills" (id 24) — HOLD**, pending the form's field list. Investigation complete: data flow is one-way out, no identifiers passed, `noreferrer` set, referenced nowhere but the menu row. |
| 2026-08-05 | **Q-B1** | **Add a Manager / Department Head role AND a reporting-manager field**, as a prerequisite to any approval or team-scope flow. Role model + scope rules to be reviewed before implementation. |
| 2026-08-05 | **Q-B2** | Course completion raising proficiency is a **tenant setting, overridable per competency**. Critical/regulated competencies require a **passed assessment**; others may auto-raise on completion. Default = assessment required. |
| 2026-08-05 | **Q-B3** | **Never auto-lower a rating.** Evidence recorded immediately on every overdue/rejected/reopened/failed task. Manager flag at **3 failures in 90 days on the same job-role task**. Remediation shown immediately regardless. Proficiency changes only on **explicit manager confirmation**. Threshold and window **tenant-configurable**. |
| 2026-08-05 | **Q-B4** | Keep the existing course record; add **`course_competency_map (course_id, skill_id, proficiency_level, is_primary)`**. **Highest-priority item in the connection plan.** Also plan migration of `sub_std_map.jobrole` from longtext to a real FK. |
| 2026-08-05 | **Q-B5** | **Restore all three missing tables** (`competency_evidence`, `competency_certification_requirements`, `s_skill_jobrole`), with a root-cause explanation and a recurrence guard. |
| 2026-08-05 | **Q-C4** | `hpbrain_*` is **HP Enterprise Brain** — Triz's other product (repo `hpbrain_backend`, Laravel 11), sharing this database. **Option B:** build G2G's connection layer **natively in G2G**; no runtime dependency on hpbrain tables. **Harvest its schema design deliberately** — event store ← `hpbrain_event_store`, threshold engine ← `hpbrain_signal_rules`, evidence ledger ← `hpbrain_evidence`, industry configurability ← `hpbrain_industries`/`_industry_templates`. Justify every deviation. Shared DB is a **risk to document**, and the likely root cause of Q-B5. The single cross-write (`LmsGovernanceController` → `hpbrain_audit_logs`) is **to be removed** — G2G writes its own audit log. Future Enterprise-Brain-as-intelligence-layer is **API contract only, never shared tables** — explicitly not Phase 3 work. |
| 2026-08-05 | **Q-A3 amendments** | (1) A **notification/alert service IS in Phase 3 scope** even though the 5 manual send-message screens are deferred — design it in `05-data-flow-contracts.md`. (2) LMS rows 77/86/78/87/88 → **HOLD-FOR-GATE-C**, deletion approved in principle but not executed until the competency audit confirms "Self skill rating" has nothing worth harvesting. (3) Document Management (97/112) → resolve in the Gate C onboarding audit; becomes SHIP if onboarding has nowhere to store documents. (4) **No nav row is removed without a reversible SQL script + a `tblmenumaster_g2g` backup**, applied as one reviewed change. Rows 184/185 backends harvested into the consolidated reporting home. |
| 2026-08-05 | **Q-A5 final** | **"Skills" (id 24) — REMOVE.** Obsolete; Employee Profiles already covers per-employee skill capture and Q-A2 makes Competency the owner. No rebuild. |
| 2026-08-05 | **Q-C1** | `s_jobrole_skills` becomes a **seed library tenants import from**. Add tenant-owned `jobrole_competency_map (sub_institute_id, jobrole_id, competency_id, required_proficiency, is_mandatory)`. **Design the import flow as a real feature** — "a new customer starts with a populated library, not a blank screen" is a selling point. |
| 2026-08-05 | **Q-C2** | Add `competency` (tenant-scoped, with `requires_assessment` per Q-B2) and `competency_kasba_item (competency_id, kasba_type, item_id, weight)`. `s_users_skills` becomes the **Skill-dimension library**, not the competency store. |
| 2026-08-05 | **Q-C3** | Add `jobrole_task_competency_map (jobrole_task_id, competency_id)`. Also plan migration of `s_user_jobrole_task` from text keys to real FKs — golden thread 2 depends on it resolving reliably. |
| 2026-08-05 | **Connection layer** | The five join tables (`course_competency_map`, `jobrole_competency_map`, `competency`, `competency_kasba_item`, `jobrole_task_competency_map`) are **one coherent schema change**, not five. **Full ER diagram required before implementation.** |
| 2026-08-05 | **Reporting** | The consolidated home **must** include a **competency gap report**, a **development plan report** and a **certification expiry report** — none of the 45 legacy reports covered these. |
| 2026-08-05 | **RBAC approved** | Role model, 4 scopes, 3 enforcement layers, `tbluser.reporting_manager_id` + `hrms_departments.head_user_id`, `role_key`/`data_scope` columns, and the 9-step sequencing — all **APPROVED**. Reuse of the `hrms_leave_role_permissions` vocabulary confirmed as the right call. Instructor/Assessor stay **assignments, not roles**. **Payroll stays hidden from Reporting Manager and Department Head.** |
| 2026-08-05 | **A1** | Employee Directory: Employee scope corrected from *self only* to **org-wide BASIC directory** (name, job title, department, work contact, manager). Sensitive fields remain restricted. |
| 2026-08-05 | **A2** | **Field-level permissions required** — screen-level is insufficient for employee record (salary, personal contact, documents), performance review (manager-only comments), competency ratings (who sees whose), payslips. → `03-rbac-matrix.md` §3.8, via named field groups + an API resource layer so restricted fields are **absent from the payload**. |
| 2026-08-05 | **A3** | **Route-to-menu map is an explicit deliverable before step 7.** Delivered: `_evidence/route-to-menu-map.csv` + `authorization-coverage.json`, via `scripts/audit-authorization.py`. **158 routes map to no menu** → `G-SEC-02`. Mapping to be made **explicit** in `routes/api.php`, not inferred. |
| 2026-08-05 | **A4** | **Delegation / acting manager** recorded in the model and in deferred scope. Not Phase 3 build work. Two rules designed in now: audit records **both** parties ("B acting for A"), and delegation **never widens scope**. |
| 2026-08-05 | **A5** | **Skip-level is a tenant setting** — `team_scope_depth`, default **direct reports only**. Cycle validation + depth bounding confirmed as **step 4** work, incl. on bulk import. |
| 2026-08-05 | **A6** | **Individual rights precedence:** individual DENY → individual GRANT → group GRANT → default deny. **Explicit DENY always wins.** Requires a tri-state on `tblindividual_rights` (grant/deny/inherit) — today a `0` is indistinguishable from "no row". **Scope is never individually overridable.** |
| 2026-08-05 | **A7** | **Leave module convergence scheduled**, not assumed. Steps 1–2 inside Phase 3 (add `role_key`, resolve approver via shared model); steps 3–4 post-Phase-3 (fold flags into the rights matrix, drop the local table). Until then `hrms_leave_role_permissions` is **read-only config — no new writers**. |
| 2026-08-05 | **Q-D1** | **Recruiter IS role 9.** Full CRUD on Recruitment; **no** access to Performance, Payroll, Competency ratings; basic-fields-only on Employee Directory. Retains read of job-role competency requirements so requisitions/scorecards generate from the framework (golden thread 7). |
| 2026-08-05 | **Q-D2** | Single role for now, **with the condition** that all authorization resolves through **one accessor returning a collection**. Direct `user_profile_id` comparisons in controllers are **prohibited**. Moving to `user_roles` later must be a data migration, not a rewrite. |
| 2026-08-05 | **Q-D3** | **Executive and Auditor stay separate.** Auditor: read + export **including audit logs**. Executive: dashboards + exception approval, **no audit log access**. |
| 2026-08-05 | **Security** | The unguarded-route finding is carried as **G-SEC-01, severity S1** in `07-gap-register.md`, with the full controller breakdown, and will be **sequenced highest-risk-first** in `08-connection-plan.md`. |
| 2026-08-05 | **Change template** | `_changes/G-NAV-01-*` is the **standard template for every data or code change at Gate D**: backup first → guard query → stated blast radius → exact rollback → G-SEC-05 pre-check. **Do not deviate.** |
| 2026-08-05 | **Verification rule** | **No number from an audit script is quoted in a document until cross-checked by a SECOND independent method** — a different parser, a manual count of a sample, or a query from the other side. Internal-consistency checks confirm consistency, **not completeness**, and cannot catch a tool's own blind spot (the 52-route miss, G-QUAL-02). |
| 2026-08-05 | **Q-D4** | **Candidate = separate portal identity.** Own table, guard and token; never resolvable in any internal module; explicit auditable candidate→employee conversion at hire; offer e-signature binds to that identity. **Phase 3 defines the model, isolation boundary and conversion step — it does NOT build the portal** (deferred scope). External trainers/vendors: same pattern, deferred; the model must generalise (hence `portal_identity` with a type discriminator). |
| 2026-08-05 | **G-SEC-06** | Approved as **S2**. Rights value becomes **tri-state (ALLOW / DENY / INHERIT)**, INHERIT the default, absence of a row also = inherit. **Same shape applied to `tblgroupwise_rights_g2g`** so both tables are consistent. Resolution order: **individual DENY > group DENY > individual ALLOW > group ALLOW > role default > deny.** |
| 2026-08-05 | **G-SEC-07 actions** | (1) `03-rbac-matrix.md` §1.2 and §4.2 corrected — the matrix **must be populated before enforcement**, it is not "a reader away". (2) **Seed source is §3.1–3.7** — those tables become a seeder; permissions are **not** re-derived. (3) §4.4 corrected — the claim that nothing before step 7 changes behaviour was **false**; the sidebar filters live on `can_view`. (4) New **§4.5 rollout plan**: seed a non-production tenant → per-role before/after menu diff → **Triz reviews every lost screen** → amend → roll out per tenant with the backup/rollback template. |
| 2026-08-05 | **G-FLOW-05** | Investigated. **Not a defect.** Issuance is fully wired (button → hook → service → route → insert) and is a manual claim gated on full content completion. Zero certificates is correct: of 1,426 enrolments, **1,425 `enrolled`, 1 `completed`**, and `lms_content_progress` has **1 row for 1 user**. **No customer can have been promised certificates.** Real finding is a funnel collapse — enrolment happens, consumption does not. Recommend auto-issue on completion via `course.completed`. |
| 2026-08-05 | **Import flow elevated** | `G-FLOW-03` + Q-C1: 169 capability rows for 386 users means the chain will be structurally correct and **visibly empty**. The seed-library import is a **first-class Phase 3 feature**, specified in `02-domain-model.md` alongside the join tables. |
| 2026-08-05 | **Custom-task split** | The existing `catalogue` / `custom` split in `create-task-modal.tsx:505` — including promotion into the Job Role Task library — is **carried forward as the foundation for golden thread 2**. No new mechanism designed. |
| 2026-08-05 | **§12 pattern** | "What this role must never see" is a **required section in every subsequent role flow**. |
| 2026-08-05 | **G-SEC-04 method** | ~537 accepted as the honest task size. **Not attempted in one pass** — sequenced by the same eight risk groups as G-SEC-01, starting Performance then Competency master data. **Regression guard required**: a check that fails when a new route is added without a menu declaration, so the gap cannot regrow. Recorded as a Gate D item. |

## Deferred scope — recorded so it is not silently lost

| Item | Decision | Ref |
|---|---|---|
| **CRM** (Marketing, Leads, Master Fields) | Deferred to a later version. Code and data intact, hidden from navigation, no Phase 3 design or connection work | Q-A4 |
| 27 further nav rows | Deferred — see `01b-scope-triage.md` for the itemised list and reasons | Q-A3 |
| **Applicant-facing candidate portal** | Phase 3 defines the identity model, isolation boundary and conversion step. **Building the portal is a separate deliverable** | Q-D4 |
| **External trainer / vendor identities** | Same pattern as Candidate, deferred. The identity model must generalise to them (`portal_identity` + type discriminator) but they are not designed now | Q-D4 |
| **Delegation / acting manager** | Approval delegation for a date range. Not Phase 3 build work; two rules designed in now (audit records both parties; delegation never widens scope) | A4 |
| **Leave module convergence steps 3–4** | Fold local leave flags into the shared rights matrix, then drop `hrms_leave_role_permissions`. First post-Phase-3 items | A7 |

---

## Open questions awaiting Triz's confirmation

Full detail in `10-open-questions.md`. Outstanding:

| Ref | Question | Blocks |
|---|---|---|
| **Q-C4** | **What is the parallel `hpbrain_*` system?** ~120 tables, own migration system, containing an event store, an industry/tenant-configurable signal-rule engine, KASBA-per-capability columns and an evidence ledger — i.e. the exact architecture Phase 3 needs. Adopt it, copy its design, or treat it as a separate product? | **`02-domain-model.md`** |
| **Q-A3** | Single-pass approval of the 104-row triage (12 SHIP / 27 DEFER / 65 DELETE / 1 HOLD) | Scope of flows in Gate B |
| **Q-A5** | "Skills" (id 24) — need the external form's field list, or confirmation it is obsolete | Nothing; recommendation is *remove* |
| Q-C1 | Where do **tenant-specific job-role capability definitions** live? `s_jobrole_skills` is global, string-keyed, not tenant-scoped | Domain model |
| Q-C2 | What table holds a **Competency as a bundle of KASBA**? None exists | Domain model |
| Q-C3 | How does a **task link to KASBA**? `s_user_jobrole_task` is text-keyed with no skill FK | Golden thread 2 |

Q-C1–Q-C3 each have a concrete recommendation in `10-open-questions.md`; they are
design proposals for your review, not blockers.

---

## Changes executed

**Application code: none.** Phase 3 remains read-only on code until Gate D (§2.1).

| Date | Change | Type | Reversible | Artefacts |
|---|---|---|---|---|
| 2026-08-05 | **G-NAV-01** — menu row 219 `access_link` corrected so Task → Permission reaches the Permission screen instead of Priority Management | **Data only** (1 row, 1 column) | Yes — rollback SQL provided; row restores byte-identical | `_changes/backup-tblmenumaster_g2g-2026-08-05.sql`, `_changes/G-NAV-01-fix-permission-menu-link.sql`, `_changes/G-NAV-01-apply.php` |

Verified after: nav cross-reference reports **0 duplicate access_links** (was 1),
broken-nav still 0.

Documentation created:
- `docs/phase3/00-progress.md` (this file)
- `docs/phase3/01-inventory.md`
- `docs/phase3/10-open-questions.md`
- `docs/phase3/_evidence/*` — reproducible extraction scripts + their output
- `docs/phase3/_raw-inventory/*` — 3,378-element raw feature catalogue (input, not deliverable)
