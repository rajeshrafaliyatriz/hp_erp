# Module write-up 3 — **LMS** (3 sub-modules)

**C18 format.** Sweep hits · hand-read of the primary config and controller ·
C35 payload checklist (both directions) · §5.1 reconciliation · CONNECTIONS TO BUILD.

**Status:** `Analysis Done`. No code changed by this document.

Sub-modules: Learning Dashboard / Catalog / My Learning · Assignments / Course
Builder / Delivery · Sessions / Records / Governance.

---

## 1. Sweep hits landing in this module

| Sweep | Hits | Consequence |
|---|---|---|
| **S-1** (verified) | `sub_std_map.jobrole` — a **longtext** holding role names | Already `Q-B4`: migrate to `course_jobrole_map`, §10 step 13 |
| **S-6** (raw) | `content_master` — **4 writers** (`LmsLearningController`, `blukCourseandQuetionGeneration`, `contentLibraryController`, `contentLibraryControllerOld`) | `contentLibraryControllerOld` is a live writer of a live table. **Candidate** |
| **C23 guard** (executed) | `assignmentController` **6 routes**, `sub_std_mapController`, `courseController`, `courseRecommandation`, `questionmasterController` — 1 each | **10 candidates.** `assignmentController` is the module's largest |
| **C27** (verified class) | `contentLibraryController` (trait present, 16 request reads), `LmsCourseEnrollController` (trait present, 9) | **Trait-present-but-unused** — the dangerous class |

**LMS carries the second-largest guard failure count after Payroll**, and two of
the five C27 controllers. Nothing here is source-corroborated yet (R6).

---

## 2. G-FLOW-05 — the funnel, restated with the numbers

Already investigated and revised. Restated because it is the module's defining
fact and the write-up should not send a reader elsewhere for it:

| Stage | Rows |
|---|---:|
| Enrolments | **1,426** |
| `lms_content_progress` | **1** (in-progress, one user) |
| Completions | **1** |
| Certificates issued | **0** |

**The chain is complete and verified** — button → `claimCertificate()` →
`lmsCertificateService.issue()` → `POST /lms/learning/certificates` →
`issueCertificate()` → insert. **Zero certificates is the correct output of the
data**, because the gate (all content complete) has never been satisfiable.

**The finding is a funnel collapse, not a broken feature:** enrolment happens at
scale, consumption does not. Under R2 this is *"never exercised"*, not *"customers
were promised certificates"* — none were ever issuable.

---

## 3. The competency↔LMS boundary — where the two modules fail to meet

Three separate breaks, all pointing the same way:

| # | Break | Evidence |
|---|---|---|
| 1 | **Courses have no competency link** | `G-DATA-01` (S1). `course_competency_map` is designed (§2.1) and unbuilt |
| 2 | **Competency's *Learning Resources* is free text**, not a course reference | `library-config.ts:172` — **L-08** |
| 3 | **Neither Competency screen can assign a course.** The drawer only *counts* `lms_assignments` where `source='competency'`; the rows are written elsewhere | `skillLibraryController.php:2435-2440` — D-C7 |

**Read together:** LMS can be told *"this assignment came from competency"* by
whoever writes the row, but **Competency cannot originate it and no course knows
which competency it builds.** The ownership model says Competency hands a measured
gap to LMS to build the skill. **That handoff exists in neither direction.**

Combined with **M-02** (a learning assignment records no gap), this is
**golden-thread-3 broken at three joints, not one.**

---

## 4. C35 checklist — payload vs validator vs insert, both directions

| Form | Files read | Verdict |
|---|---|---|
| Course Builder | `course-builder-panel.tsx:100-114` · `AiCourseController` · `sub_std_map` insert | ⚠️ **Sends only title, role, department, industry, level, applications.** The skill's *Common Errors & Tips* and *Performance Metrics* are **not** passed — the prompt is thinner than the library it draws from (L-46 class) |
| Chapter create | `LmsLearningController@storeChapter` validator · insert | ⚠️ **1 C23 FAIL on this controller family** — tenant resolution, not payload |
| Content create | `LmsLearningController@storeContent` | ⚠️ same |
| Enrolment | `LmsCourseEnrollController` | ⚠️ **C27 class** — trait imported, 9 request reads. Payload not yet the issue |
| Certificate claim | `lmsCertificateService` · `issueCertificate()` | ✅ **Clean** — chain verified end to end during G-FLOW-05 |

**Inverse direction:** no case found in this module of a column accepted but never
sent. The L-01 pattern appears to be Competency-specific.

---

## 5. §5.1 — new work versus already-approved work

| Item | Verdict | Maps to |
|---|---|---|
| `course_competency_map` | **ALREADY APPROVED** | Q-B4 / §2.1, §10 step 3 |
| `sub_std_map.jobrole` longtext → FK | **ALREADY APPROVED** | §10 step 13 |
| Learning Resources → course refs | **ALREADY APPROVED** | L-08 |
| Assignment records its gap | **ALREADY APPROVED** | M-02 (competency.md) |
| Course Builder prompt enrichment | **NEW** | small |
| `contentLibraryControllerOld` still writing | **NEW** | see L-01 below |
| 10 C23 candidates + 2 C27 | **ALREADY SCHEDULED** | part of the 46 |

**Tally: 2 new, 4 already approved, 12 scheduled.** Same shape as Organization —
**the module's substantive work was specified in Gate B.**

---

## 6. CONNECTIONS TO BUILD

| # | Connection | From → To | Why it matters to a buyer | Cost | Blocked by | Evidence |
|---|---|---|---|---|---|---|
| **L-01** | Retire `contentLibraryControllerOld` | within LMS | A file named `Old` is **still one of four live writers of `content_master`**. Two writers of one table with no stated owner is how vocabularies diverge (the Command Center defect, one module over) | **S** | ⚠️ R8 checklist + approval | S-6 |
| **L-02** | Pass the skill's error-tips and performance metrics into the Course Builder prompt | Competency → LMS | The generator draws on a library it only half reads; richer prompts are the cheapest quality win in the module | **XS** | — | `course-builder-panel.tsx:100-114` |
| **L-03** | Surface the funnel: enrolled → started → completed → certified | within LMS | **1,426 enrolments, 1 start.** Nobody can currently see that collapse; the dashboard reports enrolments as if they were engagement | **S** | — | `G-FLOW-05` |

**Deliberately NOT proposed:** anything about certificates. The chain works; the
absence of certificates is a consequence of the funnel, and L-03 makes that
visible rather than papering over it.

---

## 7. Status

`Analysis Done`. **3 sub-modules.** G-FLOW-05 restated with its numbers.
The competency↔LMS boundary documented as **three breaks, not one**. 3 connections,
one needing deletion approval.

**Module count: 17 of 32.** Next: Task (2), Talent (7), Other (4).
