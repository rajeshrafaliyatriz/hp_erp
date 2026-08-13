# Module write-up 1 — **COMPETENCY** (9 sub-modules)

**C18 format.** Sweep hits · hand-read of the primary config and controller ·
C35 payload checklist · §5.1 reconciliation · CONNECTIONS TO BUILD.
No narrative, no restating Gate B.

**Status:** `Analysis Done`. No code changed by this document.

| Sub-module | Prior depth | This document |
|---|---|---|
| Library & Taxonomy | full write-up (176 rows) | reconciled only |
| Competency Library | full write-up (78 rows) | reconciled only |
| Development & Career Path | C1b calibrated, 206 rows | **audited here** |
| Command Center | raw | **audited here** |
| Framework & Role Mapping | raw | **audited here** |
| Assessments | raw (257) | **audited here** |
| Employee Profiles | raw | **audited here** |
| Certifications | raw | **audited here** |
| Skill Taxonomy / Ontology | raw | **audited here** |

---

## 1. Sweep hits landing in this module

Only verified sweeps are used. Retired and unvalidated sweeps contribute nothing
(programme conclusion, `_evidence/sweeps/00-sweep-status.md`).

| Sweep | Hits in Competency | Consequence |
|---|---|---|
| **S-1** (verified) | `s_user_skill_jobrole` (79,295 rows — `skill`, `jobrole`, `skill_code` all text), `s_jobrole_skills` (62,208), `s_user_jobrole_task` (85,662), `s_library_map.skill_ids` (3,270, ids packed in TEXT) | **G-DATA-06 + G-DATA-07.** This module owns three of the four headline tables |
| **S-4b** (verified) | `library-tab.tsx:206` — the dead panel | **Fixed** (D-003 / L-03R) |
| **C23 guard** (executed) | `skillLibraryController` 2 routes — **fixed**; `CompetencyDashboardController@getRoleSimilarity` 1 route — likely false positive (differs at identical byte length) | 1 candidate outstanding |
| **C34** (uncalibrated) | `CompetencyDashboardController@index`, `AssessmentCycleController` ×4, `SkillDevelopmentController` ×6 | **Not quoted** pending C37 |

---

## 2. G-DATA-08 hypothesis — **CHECKED, and my guess was wrong**

**The claim to test:** does any query aggregate `s_skill_matrix` without joining
`tbluser`, making capability data silently global?

**Answer: no — but the scoping is by the wrong entity.**

`CompetencyDashboardController.php:594-601` is the module's only `s_skill_matrix`
aggregate:

```php
DB::table('s_skill_matrix')
  ->join('s_users_skills', 's_skill_matrix.skill_id', '=', 's_users_skills.id')
  ->where('s_users_skills.sub_institute_id', $subInstituteId)   // line 599
  ->distinct('s_skill_matrix.skill_id')->count(...)
```

It **is** tenant-filtered. So the "silently global" fear is **not confirmed**, and
G-DATA-08 is downgraded from *suspected leak* to *structural observation*.

**But it scopes by SKILL ownership, not by PERSON ownership.** The filter asks
*"does this skill belong to tenant X"*, never *"does this employee belong to
tenant X"*. Because `s_skill_matrix` has no tenant column, **person-ownership is
not expressible in this query at all**.

For the panel's own metric — *distinct skills that have any rating* — that is
defensible. It becomes wrong the moment a panel counts **people**. That is the
concrete argument for the decision already taken: **`skill_matrix_item` gets
`sub_institute_id`**, set at write time from the resolved identity, never from
request input, with a consistency check that every row's tenant equals its user's.

**The identical-body result that started this remains unexplained** and stays with
the C37 ten.

---

## 3. C35 checklist — payload vs validator vs insert

**Per form, three files named.** This is the item that replaced sweep S-2.

| Form | Files read | Verdict |
|---|---|---|
| Command Center quick-create | `cm-command-center.tsx:217` · `CompetencyController.php:81-87` (validator) · `:97-109` (insert) | ❌ **`competency_type` and `jobrole` sent, neither validated nor inserted — silently dropped.** Already `competency-library.md` §2.1 |
| Competency Library create/edit | `cm-competency-library.tsx:437` · `skillLibraryController.php:2500-2530` · `:2576-2590` | ✅ **Now clean.** `status` was being sent and ignored; removed in D-002 |
| Library & Taxonomy create/edit | `library-config.ts` (8 tab configs) · `LibraryController.php:55-95` (allowed lists) · same (insert) | ⚠️ **`department_id` accepted by the backend, never sent by the form** — the inverse defect. L-01/L-02 |
| Certification create | `cm-certifications.tsx` · `CertificationController.php:355-370` · `:380-395` | ✅ Clean after D-002 removed `verification_status` from the validator |
| Development plan create | `cm-development-career.tsx` (PlanForm) · `DevelopmentPlanController.php:400-425` | ⚠️ **`user_id_target` read but never collected by the quick-create dialog** — records land with a NULL subject (D-C2) |
| Assessment create | `cm-assessment-workspace.tsx` · `AssessmentController.php:75-95` | ⚠️ Same NULL-subject defect (D-C2) |

**Two directions of the same defect class:** a field sent and dropped
(Command Center), and a field accepted but never sent (Library & Taxonomy). **The
second is the cheaper fix and the one with a confirmed downstream break** (D1 —
roles invisible to HR).

---

## 4. Findings not already written up

### F-1 · Development & Career Path — the learning loop does not close · **S2**

`cm-development-career.tsx` (2,604 lines, C1b-calibrated at **0 errors**).

- **`AssignLearningForm` has no competency selector** — verified by reading the whole function. Learning is assigned without recording which gap it closes.
- **The kebab menu offers only status changes and removal** (`:1854-1869`), while `LearningAssignmentController::update` accepts `assignment_type` and `due_date`. A wrong due date can only be fixed by deleting and re-creating.

**Together:** a development plan can be created, learning assigned, and progress
tracked — and **none of it is attributable to a measured gap.** Thread 4 does not
close.

### F-2 · Command Center navigation · **CANDIDATE (R6), downgraded by V4**

`go()` pushes `/module/competency-management/${submenuId}/${submenuId}`
(`cm-command-center.tsx:371` — confirmed).

⚠️ **V4 found two errors in the original claim:** `CONTENT_MAP_LOADERS` lives in
**`hooks/use-content-map.ts`**, not `cm-command-center.tsx`, and has **eight** keys
— `1 2 3 4 5 204 186 50` — not the seven I listed. **The substance was never
re-derived**: I did not enumerate the tile submenuIds, so whether they miss the map
is unproven. **CANDIDATE until someone does.**

### F-3 · **G-MAP-01 — the core mapping table has NO CREATION PATH** · **S1**

`RoleMappingController::upsertCell` (`routes/api.php:449`) is the **sole** writer of
`s_user_skill_jobrole` (79,295 rows). Command Center's *"Create Role Mapping"*
button does **not** call it (it creates a framework instead — D-C1).

**Answered, and it is worse than a wiring bug.** `QUICK_ACTIONS` line 55 is
`{ label: 'Create Framework', kind: 'framework' }`; line 56 is
`{ label: 'Create Role Mapping', kind: 'framework' }` — **the same kind.** The
button is not mislabelled; it is bound to another control's handler.

**So role mapping has no create path at all.** `s_user_skill_jobrole` (79,295 rows)
can only be edited **cell by cell** through `upsertCell`. **A new tenant cannot
build a role→skill mapping through the UI**, which is step one of the capability
chain — a **golden-thread-1 break**, not a screen defect. Raised as **G-MAP-01
(S1)**.

### F-4 · Assessments — no link from assessment to the gap it measures

`AssessmentController::store` inserts `user_id` NULL when quick-created (line 88).
Assessment rows carry a title and a cycle but no reference to the competency or
gap under assessment beyond free-text.

### F-5 · Certifications — G-CERT-01 confirmed at screen level

`cm-certifications.tsx` collects a free-text certification name and issuing body.
**There is no type catalogue to pick from**, because none exists (G-CERT-01).
Every credential is an independent string.

### F-6 · Skill Taxonomy / Ontology — the ontology is an iframe

`cm-taxonomy-ontology.tsx` (6 inventory rows) renders an external view. **The
adjacency it displays is not computed from `s_user_skill_jobrole`**, so the
"related roles" a user sees are unrelated to the mapping data the product holds.

---

## 5. §5.1 — new work versus already-approved work

| # | Verdict | Maps to |
|---|---|---|
| F-1 learning→gap link | **PARTLY APPROVED** | `course_competency_map` (§2.1) supplies the course side; the **assignment→gap reference is new** |
| F-1 edit controls | **NEW** | XS — the endpoint already accepts both fields |
| F-2 navigation | **ALREADY RAISED** | C-08, flagged as cross-cutting, **not Competency's to own** |
| F-3 role-mapping writer | **ALREADY APPROVED** | `jobrole_competency_map` (§2.1) replaces `s_user_skill_jobrole` |
| F-4 assessment subject | **ALREADY APPROVED** | §11 (v) polymorphic integrity + C-07 |
| F-5 certification type | **ALREADY APPROVED** | §10.1 `certification_type`, steps 3b/9b |
| F-6 ontology | **NEW** | but see below — likely DELETE, not build |

**Tally: 2 new, 4 already approved, 1 partly, 1 cross-cutting.** The module's
remaining work is **overwhelmingly already scheduled** — which is the expected
result once G-DATA-06's precondition is recognised.

---

## 6. CONNECTIONS TO BUILD

| # | Connection | From → To | Why it matters to a buyer | Cost | Blocked by | Evidence |
|---|---|---|---|---|---|---|
| **M-01** | Add edit controls for learning `assignment_type` and `due_date` | within Competency | The endpoint **already accepts both**; today a wrong due date needs delete-and-recreate | **XS** | — | `cm-development-career.tsx:1854-1869`; `LearningAssignmentController.php:250-255` |
| **M-02** | Record which **gap** a learning assignment closes | Competency → LMS | Without it, thread 4 cannot close: learning is assigned but never attributable to a measured need | **M** | `course_competency_map` (§10 step 3) | `cm-development-career.tsx` `AssignLearningForm` |
| **M-03** | **Give role mapping a create path** — point *"Create Role Mapping"* at a real bulk-create over `upsertCell` | Command Center → Framework | **G-MAP-01, golden-thread-1 break.** Today the mapping table can only be edited cell by cell; **a new tenant cannot build a role→skill mapping at all** | **S–M** | — | `cm-command-center.tsx:56`; `routes/api.php:449` |
| **M-04** | `skill_matrix_item.sub_institute_id`, set from resolved identity | Competency → the guard suite | **Makes tenancy expressible**, so C23/C34 can check it at all. Free now: 169 rows, table being rebuilt | **XS** *(inside step 12)* | §10 step 12 | §2 above; G-DATA-08 |

**Deliberately NOT proposed:**

| Not building | Why |
|---|---|
| A rebuilt Taxonomy Ontology (F-6) | It is an iframe showing adjacency unrelated to the product's own mapping data. **Once `jobrole_competency_map` exists, real adjacency is computable — the iframe should be DELETED, not reimplemented.** ⚠️ Deletion needs approval and an R8 checklist |
| Navigation fix (F-2) | Cross-cutting; belongs to a shared navigation contract, not to Competency |

---

## 7. Status

`Analysis Done`. **9 sub-modules covered** — 2 by prior full write-ups, 7 here.
G-DATA-08's hypothesis checked and **downgraded** (§2). C35 checklist applied to 6
forms with 18 files named. 4 connections, of which **2 are XS and one is free**
inside a migration already scheduled.

**Module count: 9 of 32.** Next: Organization (5), LMS (3), Task (2), Talent (7),
Other (4).
