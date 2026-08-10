# Phase 3 Progress

> ## ⚠️ THIS FILE WAS RECONSTRUCTED 2026-08-06 AFTER I DESTROYED IT
>
> A backtick inside a `python -c "..."` shell string opened a subshell, the command
> mangled, and the write **truncated this file to zero bytes**. It was **untracked
> in git**, so no copy existed.
>
> **CORRECTED TIMELINE.** The truncation happened between **18:23 and 18:34**, not
> at 19:33 as first reported. **The file was dead for roughly an hour**, and several
> subsequent "updates" wrote into an empty file **and reported success**.
>
> **So the loss was one bad command PLUS an hour of unnoticed silent failure** - and
> the second half is the larger lesson. A write that silently succeeds into nothing
> is the same failure class as a checker that reports a confident wrong number:
> **it looks like it worked.**
>
> **What is below is rebuilt from the working session.** The decisions, rules and
> gate states are accurate — all are cross-checkable against `07-gap-register.md`,
> `09-implementation-log.md`, `_evidence/sweeps/` and the module write-ups, which
> are intact. **What may be missing is detail that lived only here**: some older
> decision-log rows and deferred-scope notes.
>
> **Fixed going forward:** this file is written with the Write tool, **never**
> through a shell string. **R18: committed to git after every write.**
>
> ### Recovered vs rebuilt
>
> | Section | Provenance |
> |---|---|
> | Gate state, C17/C18, G-DATA-06, rules R1-R18, sweep conclusion, 17-of-32, Gate D thread, instruments | **REBUILT** (newer, and correct) |
> | **Decisions made** - 43 dated rows to 2026-08-05 | **RECOVERED** from the snapshot |
> | **Deferred scope** - 6 items | **RECOVERED.** It had been silently dropped |
> | **Changes executed** - G-NAV-01 | **RECOVERED** |
> | Decisions after 2026-08-05 | **REBUILT** from `10-open-questions.md`, `07-gap-register.md`, `09-implementation-log.md` |
> | **`00-progress-ORIGINAL-1823.md`** | **PRIMARY EVIDENCE - the recovered 37,983-byte original. KEPT, not deleted (R8).** Every claim about what was lost is checkable against it |
> | **Status legend · Gate checklist · Module checklist** | **RECOVERED 2026-08-06 from a Recycle Bin snapshot** — ⚠️ the Gate checklist came back with a **stale row** (*"2 of 32 delivered"*) that survived the merge unnoticed until 2026-08-07. **Corrected. A recovered section is a snapshot, not a current statement** taken 18:23:51, the last copy before the truncation. These three existed **only** in this file - they were in neither the user's snapshot nor my reconstruction |

Last updated: 2026-08-06
Current gate: **C (audit) and D (foundation build) RUNNING IN PARALLEL**
Gate B: **SIGNED OFF** · Gate A: **SIGNED OFF**

---

## ⛔ C24 — COMPANY-LEVEL RELEASE PRECONDITION

> **NO CUSTOMER TENANT IS CREATED ON THIS PLATFORM UNTIL A TENANT-ISOLATION TEST
> SUITE PASSES END TO END.**

### WHAT THE SUITE COVERS TODAY — stated so a green read-half is never read as the gate passing

| Half | Routes | Status |
|---|---:|---|
| **READ** (GET) | **912** | **Instrument VERIFIED** by isolated re-run (23-route stratified sample, 20/23 agree, 3 explained by the G-LMS-SEC-01 fix). 46 FAIL + 4 LEAK-NOSCOPE worked |
| **WRITE** (POST/PUT/PATCH/DELETE) | **772** | **NOT TESTED AT ALL** |

> **C24 IS NOT SATISFIABLE UNTIL THE WRITE HALF EXISTS.**
>
> **AND THE READ HALF HAS A KNOWN BLIND SPOT.** A route pinned to a THIRD tenant
> returns the same response as A and as B, so it scores PASS - G-SEC-17 found
> nine such sites that C23 structurally could not see. Closing it needs a
> third-tenant marker pass **and** the static tenant/role-literal check, the
> latter folded into C23's suite as a standing check.
>
> **PRECONDITION ON THE WRITE HALF:** confirm it does not inherit the two-tenant
> design before it is built.
>
> The read half being green says the read half is green. It says **nothing** about
> 772 write routes, and `assignmentController`'s approval path — the one proven
> unauthenticated write — sat in exactly that untested half.

A business rule, not an engineering task, recorded so it cannot be traded away
under delivery pressure by someone who was not in this conversation. The gate is a
**passing test suite**, not a *completed fix* — because C22 showed a fix can look
done and not be.

---

## G-DATA-06 — THE PHASE HEADLINE

**283,126 relationship rows are joined by string matching, not keys**, across four
tables verified individually: `s_user_jobrole_task` (85,662),
`s_user_skill_jobrole` (79,295), `s_jobrole_skills` (62,208), `s_jobrole_task`
(55,961).

**Not 283,126 defects** — 283,126 rows in four tables, each resolving its
relationship by string. The data is **not wrong today**; any rename silently
detaches it, and nothing can join by key. Test data, so the **structure** is the
finding, not the volume.

**L-11 is the precondition for the product functioning**, not one connection among
twenty-three (`02-domain-model.md` §10.-1).

Separately and **independently**: **36** text columns reference an entity with no
`*_id` (C30 split 36/5/8). **The two figures must never be conflated.**

---

## PLAN — C17 / C18

Two threads run in parallel; progress on each is reported separately.

### C18 — the trade-off, recorded so it is never mistaken for an oversight

> **DECIDED, EYES OPEN: the per-element coverage ledger (C11) is ABANDONED.** We
> will not be able to say each of 3,378 elements was individually examined.

**Kept instead, and worth more to a customer:** every known defect pattern checked
across the whole codebase, plus the golden-thread modules read by hand.
**Evidence for the trade:** across two full write-ups, exhaustive enumeration
produced **zero findings on its own**.

**One write-up per MODULE** — competency (9) · organization (5) · lms (3) ·
task (2) · talent (7) · other (4). Each contains only: sweep hits · a hand-read of
the primary config and controller · the C35 payload checklist · the §5.1
reconciliation · CONNECTIONS TO BUILD.

**Token rules:** the raw inventory is a lookup, not reading material · read by
pattern, not by file · output deltas only · 100% verification for negative and
trust/security claims, 10% sample otherwise.

---

## GATE D — foundation build thread

| # | Item | Status |
|---:|---|---|
| 1 | **L-03R** — delete the dead panel | ✅ **BUILT** (D-001). 2 files, −404 lines |
| 2 | **G-COMP-01 + G-SEC-08 + the rejected-competency dead end** | ✅ **BUILT** (D-002). 7 sites, 4 files. ⚠️ visible behaviour change: new competencies born `Pending` |
| 3 | **G-SEC-09** — `skillLibraryController` onto `ResolvesApiIdentity` | ✅ **BUILT** (D-003). Guard **2 FAIL → 0** |
| 4 | **G-SEC-10** — `PayrollController` | ✅ **BUILT** (D-004). Guard **9 FAIL → 0**, re-verified. Residual: 4 `user_id` sites untouched — subject-vs-identity, not tenant |
| 5 | **C23 guard** — property test, now the completion criterion | ✅ **LIVE.** 46 FAIL across 30 controllers remain |
| 6 | **C19** — the picker mechanism, built once | Not started (M–L) |
| 7 | **L-01, L-02** as configuration on C19 | Blocked on 6 |
| 8 | Join tables + `certification_type` + restored tables, **one migration** (§10) | Not started |
| 9 | `reporting_manager_id` + `head_user_id`, with cycle validation | Not started |
| 10 | Tri-state rights + **populate the matrix**, with before/after menu diff | Not started |
| 10b | **G-SEC-12 — caller-supplied audit provenance (33 candidates)** | **Not started. BLOCKS item 11** — the event store assumes `actor_id` is trustworthy |
| 11 | Event store + projector/reactor split + `task_status_history` | Not started. **Blocked by 10b** |

**C20 verification protocol** (`11-verification-protocol.md`): I **cannot** start
the app or click a UI, so I can never mark my own work `Verified`. Cap of 3
`Built-unverified`; `API-verified-UI-pending` does not count against it.

---

## GATE C — audit thread

### Module write-ups: **30 of 32 sub-modules — GATE C EFFECTIVELY CLOSED**

**The two not audited, named** (`08-connection-plan.md` §0): **CRM** — deliberately
out of scope per Q-A4, recorded not audited; and **"Development & Career Paths"**
— a **duplicate checklist row** for a screen audited under its singular name.
Neither was "not reached".

⚠️ Count correction: the checklist carries **11** Competency rows, my write-up
counted 9. Difference = the duplicate + Skill Taxonomy/Ontology covered as one
section. **No sub-module was skipped.** Reconciled: 30 audited · 1 duplicate · 1
out of scope = 32.

**✅ GATE C VERIFIED AND CLOSED** — `12-gate-c-verification.md`. V4 sample error rate
**3.3%** (1 of 30, under the 5% bar); V7 reconciliation **clean, no dropped work**.
V6 corrected four headline numbers. **The audit is closed; no further verification.**

**`08-connection-plan.md` STARTED — §1–§3 complete** (diagnosis, all 9 threads
traced, dependency tiers). §4–§11 in assembly.

| Module | Sub-modules | Status |
|---|---:|---|
| **Competency** | 9 | ✅ `competency.md` |
| **Organization** | 5 | ✅ `organization.md` |
| **LMS** | 3 | ✅ `lms.md` |
| **Task** | 2 | ✅ `task.md` |
| **Talent** | 7 | ✅ `talent.md` |
| **Other** (HRIT, Agentic, Reports, CRM) | 4 | ✅ `other.md` |

### Sweeps — CONCLUDED, 7 of 7

**2 verified and productive** (S-1 → the 283,126 headline; S-4b → proved a
negative) · **1 retired with reason** (S-2, C35) · **1 retired** (S-5, C36) ·
**3 unvalidatable or unvalidated**.

> **Both productive sweeps were STRUCTURAL. Every behavioural sweep failed.**
> **Structural questions get tools; behavioural questions get a careful reading by
> a person.** The C13 split predicted every case.

### Instruments

- **C23** differential tenant guard — **46 FAIL** across 30 controllers, from 912 GET routes. **A floor**: 454 UNTESTABLE, 864 write routes untested.
- **C34** structural no-scoping test — **114 candidates, NOT QUOTED**; no calibration exists yet. **C37** bounds it: hand-verify ten by data sensitivity, then either calibrate or close as a proven negative. **1 of 10 done** (`IndustryController`, false positive).
- **C28** content markers — **retracted**; essentially no title in tenant 3 is unique to it, so content detection on titles cannot work here.

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
| C | `06-feature-audit/*` write-ups | ✅ **CLOSED** | **All six module write-ups delivered.** 30 audited · 1 duplicate · 1 out of scope = 32 |
| C | `12-gate-c-verification.md` | ✅ **VERIFIED** | V4 sample error rate **3.3%** (1 of 30); V7 reconciliation **clean**. **Gate C stands** |
| D | `08-connection-plan.md` | **In Progress** | §1–§3 written (diagnosis, 9 threads traced, dependency tiers). §4–§11 next |
| C | `06-feature-audit/00-pace-c9.md` | **Awaiting decision** | C9 pace report. FULL/SHALLOW split proposed |
| D | `08-connection-plan.md` | Not Started | |
| D | `09-implementation-log.md` | **Current** | **D-001 built** (L-03R). R7 retroactive cost review recorded here |
| — | `10-open-questions.md` | **Current** | Single register; **24 questions — all answered.** Q-L1/L2/L3 closed 2026-08-06 |

---

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

---

## Standing rules

Full text in `07-gap-register.md`. **R1** second method · **R2** provenance asked ·
**R3** five rows by eye (+ *"test data" is not a statement about a table's value*) ·
**R4** the checker is the primary suspect · **R4b** resolve disagreeing counts
first · **R5** status summary every turn · **R6** a sweep produces candidates ·
**R7** an estimate names its files · **R8** pre-deletion checklist · **R9** re-read
frontend consumers after a server change · **R10** name the proxy · **R11**
verify scope-shrinking assumptions **before** using them · **R12** land a queued
item every turn *(a turn spent entirely on an incident counts as landed work,
provided the incident is reported and closed)* · **R13** standing authority · **R14** turns end at boundaries ·
**R15** assert the column exists · **R16** every sweep names a known-positive — **the rule with the best strike rate: it caught S-2's 111 phantom endpoints AND G-SEC-23's under-count, two of the three most consequential measurement errors of the phase** ·
**R17** check your own artefacts before writing a new script · **R18** commit
`docs/phase3/` after every write to this file, **and assert after every write that
the file is non-empty and still contains an expected marker line**.

**R18b — THE QUEUE SECTION AND THE FOUNDATIONS COUNTER ARE REWRITTEN ON EVERY
WRITE TO THIS FILE, NOT APPENDED TO.** A section that is only appended to **goes
stale silently while the file's timestamp says it is current** - which is how
"FOUNDATIONS 3 of 6" survived ~8 shipped items while `07-gap-register.md` stayed
correct. **The register being right while the queue is wrong is the dangerous
combination, because the QUEUE is the recovery path a context reset reads first.**

**R18c — RECONCILE ON EVERY WRITE, AND REPORT IT.** The queue's "done" count must
agree with `09-implementation-log.md`'s entry count. They disagreed by **13** when
this rule was introduced: twelve security fixes were in the register and in git but
had never been written to the log. **Report the reconciliation in the status line
each time.**

**R18d — GIT DISCIPLINE IS A GUARD, NOT VIGILANCE.** Third repeat of the same
pattern, and the one with real blast radius: **~75 pre-existing modified files sit
in this tree that are not mine**, and a swept commit puts someone else's
half-finished work into history under my name.

- **NEVER `git add <directory>` or `git add -A`. Explicit file paths only.**
- **Before every commit:** `git status --short`, and confirm the staged list
  matches the files touched THIS turn, **by name and by count**.
- **State the file count in the D-entry.** That number is the check.

**R18c(ii) — A CHANGE IS DONE ONLY WHEN IT IS IN THE LOG WITH A COMMIT.** A
sentence in a reply is an **intention**, not an outcome. **Menu 100's re-grant was
described as done and was never applied** - it sat unlisted for five turns because
*something SAID became something BELIEVED and nobody checked whether it happened*.
**I reported an intention as if it were an outcome.** It cost one screen here; the
same failure hides larger things. **The queue-log reconciliation is what enforces
this** - a fix with no D-entry and no commit is not done.

**R10 — two worked examples, both from the Employee qualifier batch.**

1. **A proxy that assumes a file layout fails silently when the layout is
   dynamic.** "Does a component directory exist for this screen?" returned *no*
   for menu 26 — **and also for menu 22, which is live.** The Next.js tree is
   `app/module/[moduleId]/[menuId]/[submenuId]`, fully dynamic, so **no** screen
   has a static directory. The known-positive (R16) is what exposed it.
   Re-tested through the component registry instead.
2. **A proxy can be inverted by an unrelated fix.** Counting references to
   `$context['user_id']` per controller, as a stand-in for caller-scoping, reads
   backwards after G-SEC-12: `ProjectController` has 13 and **none scope a read**
   — they are `created_by` / `updated_by` / `archived_by`. **The provenance fix
   populated the detector with false positives.** Only a `where()` on the
   caller's id **in the read path** counts.

3. **A harness can measure the wrong key and look clean.** The R9 sidebar check
   guessed the child key (`children` / `submenu`) and reported
   **`leaves == modules` for every one of the nine roles** — nine identical
   suspicious rows that still read as *renders*, because the assertion under test
   (non-empty) passed. The controller nests under `menus`, then `submenus`.
   **Same class as every other proxy failure this phase: the harness was
   measuring the wrong thing, not measuring nothing.** A uniform result across
   independent subjects is the tell.

4. **THE CONTROLLER'S CODE IS NOT THE ENDPOINT'S BEHAVIOUR** (beside R10c).
   G-COMP-SEC-01 was reported as read *and* write because the controller had no
   ownership check — but all five write routes carried
   `middleware('profile:admin,hr,manager')`. **It cuts both ways:** an
   unguarded-looking controller may be protected at the route, and a
   guarded-looking route may do nothing useful in the controller. **Neither layer
   alone is the behaviour.** Read the route file and the controller, or call the
   endpoint.

**R4 — two worked examples, both from the security queue.**

1. **INFERRING A CAUSE FROM A SILENT PROCESS.** A probe produced no output and I
   reported it as *"hung, blocking on an outbound call"*. It had died of
   **memory exhaustion**. **"No output" means the process stopped - it does not
   name why.** Same shape as reading `last_used_at IS NULL` as *"no token was
   ever used"*: an absence being read as a specific cause. It is the same family
   as a proxy, **one step earlier** - before there is even a measurement to be
   wrong about.
2. **A HARNESS THAT DIES ON A SPECIFIC INPUT IS TELLING YOU SOMETHING ABOUT THE
   INPUT.** The route that killed the probe was the finding (G-SEC-15): an
   unauthenticated GET loading an unbounded result set. **Second time this phase
   a harness failure was the signal rather than the noise** - the first being the
   container conflict that an empty sidebar exposed.

**THE CASE FOR THE MEASUREMENT REQUIREMENT ITSELF.** C23 is the worked example.
I argued the guard was unaffected by G-HARNESS-01 *"because its calls carry no
token"* - and the guard **does** carry one, at `c23-tenant-guard.php:34`. The
argument was factually wrong. **The verification holds anyway, because it was
measured rather than argued.** A correct conclusion reached from a false premise
is not evidence; only the measurement was.

**KEEPING RAW RESULTS RATHER THAN SUMMARIES MEANS A STALE RUN BECOMES A
REGRESSION BASELINE.** `c23-result-FULL-912.json` predates the G-LMS-SEC-01 fix,
so re-running three of its routes showed FAIL -> identical: **C23's independent
instrument confirmed the fix from a run recorded before the fix existed.** A
summary ("46 failures") could not have done that. **This is why the full result
file was worth recovering.**

**R20 — THE BOUNDARY OF WHAT YOU READ IS THE BOUNDARY OF WHAT YOU KNOW.**
*A check, not a caution.* **Before reporting on any code path, establish what
reaches it, and state that chain explicitly in the write-up:**

| Reading | Establish |
|---|---|
| a method | its **callers** |
| a controller | its **route file** |
| a route | its **middleware** |

Two instances, and both times **the missing layer was ONE HOP AWAY** while the
read stopped at the file containing the defect:

- **G-COMP-SEC-01** - the controller's code was not the endpoint's behaviour; the
  five write routes carried `profile:admin,hr,manager` and I never opened
  `routes/api.php`.
- **getDataPre** - the method's code was not the method's reachability; the
  literal is unconditional inside the method, but `index()` reaches it only via
  `preload_lms`.

**R21 — TWO PRACTICES CARRIED FORWARD FROM G-SEC-20.**

1. **STATE IT PRECISELY, NOT MAXIMALLY.** G-SEC-20 records that CSRF means an
   outsider needs a token from a page first *and* that any session holding one can
   delete any tenant's module. **Conceding the barrier makes the finding more
   credible, not less** - a security reviewer trusts a document that does it.
2. **CHECK EXISTING DATA BEFORE ADDING VALIDATION TO A POPULATED COLUMN.**
   `custom_module_tables` was read first: 1 row, matching the new whitelist, so
   nothing legitimate broke and no payload was already stored. **Standard from now
   on for any validation added to a column that already holds values** - against
   real customer data this is the difference between a fix and an outage.

**R22 — VALIDATE AT THE POINT OF USE, NOT ONLY ON WRITE. GENERALISES.**

When a "validate on write" rule is added to a field that has been stored
unvalidated, **validation at the door does not fix the data already there.**
G-SEC-20 proved the door had been open for the feature's entire life, so the
column already held whatever callers had sent. **The guard therefore belongs where
the value is USED**, which no path can bypass - `DynamicModel::assertSafeTable()`
(G-SEC-21) rather than more checks in `tableStore`.

**Applies to every validate-on-write fix in this codebase, not just this one.**
The door check is still worth adding; it is simply not the fix.

**A GUARD MUST FAIL LOUDLY.** `assertSafeTable()` throws rather than returning an
empty result. A guard that fails quiet returns *"no records"*, **which reads as an
empty table rather than a refused identifier - and nobody investigates an empty
table.** Standard for every guard from here.

**R18 — the real root cause.** The backtick was the mechanism; **the cause was
that a 40-turn engagement's tracking file was untracked in git.** Nothing else
would have made a single mangled command unrecoverable.

> **Thirteen under-reports from scope-narrowing assumptions. Zero over-reports.**
>
> **R4 checker-artefact tally: ~14 and counting** (latest: shell escaping turned
> ``/`	` into control characters and produced three false 'class does not
> load' results). **The shape is now the norm, not a surprise** - logged as a
> count, not as a new example, unless the shape itself is new.
> R11's mechanism is beyond coincidence.

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

---

## Decisions made — AFTER the snapshot (2026-08-06)

Rebuilt from the intact artefacts. The recovered table above ends at 2026-08-05.

| Date | Ref | Decision |
|---|---|---|
| 2026-08-06 | **Q-E1** | `task.skill_id` is **hand-picked at creation**, job-role-suggested. **Catalogue wins**; the instance is an override tagged `confidence`. 33% null = no signal, do not guess |
| 2026-08-06 | **Q-E2** | **Measure per KASBA item**, derive a weighted roll-up. One service, two numbers reported. **Unmeasured ≠ zero** |
| 2026-08-06 | **Q-F1** | Notifications: **fixed wording + tenant-substitutable terminology**. Both tables built now, so renaming *employee* → *clinician* is data entry, not a refactor |
| 2026-08-06 | **Q-L1** | Of 25 orphan Library fields: **BIND 10 / NOTE 13 / SUBSTITUTE 2**. BINDs become L-15…L-23, each carrying a typing change (R-a); two bind to **existing** fields rather than new systems (R-b); three are display-only (R-c) |
| 2026-08-06 | **Q-L2** | A retired skill **persists until the cycle closes**. Filter at **assignment** time, never read time — an in-flight assessment is a measurement being taken |
| 2026-08-06 | **Q-L3** | **One shared category table with per-taxonomy applicability.** Cross-taxonomy reporting without forcing irrelevant categories onto every tab |
| 2026-08-06 | **Corrections 1–5** | **This database is TEST DATA ONLY — no production tenant, no customer.** Every "for now" compromise re-examined; separate DBs now; `s_skill_matrix` semantics decoded; polymorphic integrity required |
| 2026-08-06 | **C11 ABANDONED** | The per-element coverage ledger is dropped, **eyes open**. Enumeration produced **zero findings on its own** across two full write-ups |
| 2026-08-06 | **C17** | **Gate D opened early**, in parallel with Gate C. The foundation items depend on none of the remaining audits |
| 2026-08-06 | **C19** | **Build the picker mechanism ONCE** — id-bearing meta bucket, `LibraryMeta` carrying ids, one closed-picker control with a permission-gated create, generic payload mapping. Then every entity binding is **configuration** |
| 2026-08-06 | **§10.0 BINDING RULE** | **Entity → closed picker + permission-gated inline create. Vocabulary → open choice.** Decided by **ownership**, not drift: a department must never be created as a side effect of typing into a Competency form. Pre-answers L-04, L-07, L-08, L-09 and every field L-11 touches |
| 2026-08-06 | **C20** | Verification protocol. **I cannot start the app or click a UI, so I can never mark my own work `Verified`.** Cap of 3 `Built-unverified`; `API-verified-UI-pending` does not count against it |
| 2026-08-06 | **C22** | Phase 1's auth sweep counted **`findToken()` as a guard**, so a controller that validates a token and discards its owner passed it. **Two of three scripts read only `api.php`.** Inheriting conclusions listed, not re-audited |
| 2026-08-06 | **C23 INVERTED** | **Write the guard FIRST, before any fix.** Its failure list *is* the worklist, with a completion criterion that is not "we think we got them all". Now gates four figures |
| 2026-08-06 | **C24** | **Company-level release precondition** — no customer tenant until a tenant-isolation suite passes end to end |
| 2026-08-06 | **C25** | **No security figure is quoted until reconciled.** 1,676 routes across six files, not 739. **The Blade assumption was FALSE** — `authMiddleware` accepts a session **or a bare token** — so scope went **35 → 66** controllers. G-SEC-01/04 and the 279 remain **unquotable** |
| 2026-08-06 | **C30** | The S-1 49 splits **36 references / 5 own-identity / 8 noise**. Only the **36** is quotable. **283,126 is independent of it** |
| 2026-08-06 | **C33** | Global libraries are **copy-at-seed**: no tenant column, no write path. A customer renaming a job role **cannot** affect another customer |
| 2026-08-06 | **C35** | **S-2 RETIRED** → a module-write-up checklist item (payload vs validator vs insert, three files named per form) |
| 2026-08-06 | **C36** | **S-5 RETIRED.** A failed sweep is not rebuilt by default — ask whether a tool is cheaper than a checklist item |
| 2026-08-06 | **C37** | Bounds C34's 114: hand-verify **ten** by data sensitivity, then either calibrate or **close as a proven negative** |
| 2026-08-06 | **G-DATA-08** | **Add the tenant column.** `skill_matrix_item` **and** `s_skill_matrix` get `sub_institute_id` in §10 step 12, set at write time **from the resolved identity, never request input**, plus a consistency check that each row's tenant equals its user's. Reason: **it is what makes the guards work at all** |
| 2026-08-06 | **G-MAP-01** | The 79,295 mapping rows came from `SchoolSetupController.php:392-408` at **tenant provisioning**. A bulk-create mechanism **exists**, reachable only from signup. M-03 re-costs to *"surface an existing path"* |
| 2026-08-06 | **Sweep conclusion** | **Structural questions get tools; behavioural questions get a careful reading by a person.** Both productive sweeps were structural; every behavioural one failed |

---

## Changes executed — AFTER the snapshot

**Gate D opened 2026-08-06.** Full detail in `09-implementation-log.md`.

| Ref | Change | Guard result | Status |
|---|---|---|---|
| **D-001** | L-03R — delete the unreachable library detail panel (366 lines) | n/a | `Built` |
| **D-002** | G-COMP-01 + G-SEC-08 — server owns `approve_status` / `verification_status`; rejected competencies made resubmittable | n/a | `Built`. ⚠️ visible: new competencies born `Pending` |
| **D-003** | G-SEC-09 — `skillLibraryController` onto `ResolvesApiIdentity` | **2 FAIL → 0** | `API-verified-UI-pending` |
| **D-004** | G-SEC-10 — `PayrollController`, **26 sites in four styles** | **9 FAIL → 0** | `API-verified-UI-pending` |

---

## Open questions

`10-open-questions.md` — **24 questions, ALL ANSWERED.**

**Reconciled against the snapshot:** the six the snapshot listed as outstanding —
**Q-C4, Q-A3, Q-A5, Q-C1, Q-C2, Q-C3** — were **all answered on 2026-08-05** and
appear in the recovered Decisions table above. **Nothing is awaiting Triz.**

---

## Still owed — carried explicitly so they do not drop again

> **Rule: nothing leaves this section without either a commit reference or an
> explicit decision not to do it.**

| Item | State |
|---|---|
| **4 `user_id` sites in `PayrollController`** | The **actor** half, not the tenant half. Must be resolved or explicitly cleared as subject-not-identity |
| **G-MAP-01 mis-wired button** | `'Create Framework'` and `'Create Role Mapping'` both map to `kind:'framework'`. **One-line fix on an S1 golden-thread-1 break.** Scheduled |

---

## Queue

> ### ⚠️ THIS SECTION IS REWRITTEN, NEVER APPENDED TO (R18)
> Last rewritten **2026-08-10**. It went stale for ~8 shipped items once — the
> "2 of 32" incident repeating — while the gap register stayed current. **The
> register being right while the queue is wrong is the dangerous combination,
> because the queue is the recovery path.**

**RECONCILIATION (R18, every write):** queue "done" rows = **41** ·
`09-implementation-log.md` entries = **41** · **AGREE.**
*(They disagreed by 13 before this rewrite: the security stream shipped 12 fixes
that were recorded in `07-gap-register.md` and in git, but never as D-entries.)*

### FOUNDATIONS — **5 of 6**

| # | Build item | State |
|---:|---|---|
| 1 | `talent_interviewpanelController` | ✅ `15791bca` |
| 2 | G-SEC-12 actor identity — 76 sites, 16 files | ✅ `d70a204c` — unblocked the event store |
| 3 | Join-table migration, as ONE change | ✅ `7df8c1c7` — 12 tables, 3 columns |
| 4a | Tri-state rights columns | ✅ `5e302651` |
| **4b** | **Populate the rights matrix** | ✅ **APPLIED** `5af9b26a` — 5,621 rows, 99 profiles, 0 orphans. Nine roles render through the real path (R9). Administrator retains 1/8/23 |
| 5 | `reporting_manager_id` + `head_user_id` + cycle validation | ✅ `f293edb0` |
| **6** | **Event store + projector/reactor split + `task_status_history`** | ✅ **DONE** (all 4 slices) (store D-030; `g2g_audit_log` projection + `TaskAuditService` converted to emit, D-031). store D-030 · audit projection D-031 · catalogue D-032 · **D-034: `task_status_history` (F2 detectable), `ReplayRunner` + runbook, first reactor throwing on replay, and a REAL REBUILD proving the reactor ledger permanent** |
| **F-07b** | Backfill + unmatched report + drops (R8) | 🔄 **BACKFILL APPLIED** (D-036) - 229,681 of 244,253 populated, **14,572 HELD as NULL**, 0 cross-tenant fks. **2 of 4 tables BLOCKED**: their values match up to 10 tenants at once. **Awaiting decision on the 29,925 orphans.** Nothing created, nothing dropped |

### SECURITY STREAM — **16 fixes shipped**. **THE PAUSE IS NOW IN FORCE.**

| Finding | Fix | Commit |
|---|---|---|
| G-COMP-SEC-01 | Ownership check on all 13 competency-profile methods | `27f3ab10` |
| G-AUTH-01 | `RequireProfile` exact `role_key` match, not name substrings | `f9cd7ede` |
| G-LMS-SEC-01 | Assignment endpoints were **unauthenticated** — adopt `ResolvesLmsIdentity` | `18b3147b` |
| G-SEC-15 | `getSkillCompetency` — auth + bounded pagination + token tenant | `9fc3a42f` |
| G-ATT-SEC-01 | Punches resolve the subject from the token | `33f45571` |
| G-SEC-17 | Nine hardcoded tenant/role literals | `6dfdd2a2` |
| G-SEC-19a | SQL injection, `lmsmappingController` | `24d63869` |
| G-SEC-19b | Injection sweep — 2 more sites bound; ORDER BY ruled out by test | `7458b4a1` |
| G-LEAVE-SEC-01 | `leaveSubject()` resolves the subject against the caller | `acfcf4d1` |
| G-SEC-20 | Second-order injection — arbitrary table drop | `17fa3b2f` |
| G-SEC-21 | Dynamic table names validated at the point of use | `80ea67a7` |
| G-SEC-22 | `tableDelete` auth + tenant scoping | `c3dd4b71` |
| **G-SEC-23a** | `api/feedback/{id}` — auth + tenant clause (chain A) | see D-027 |
| **G-SEC-23b** | `api/competency/audit/user-actions/{userId}` — name lookup scoped (chain A) | see D-027 |
| **G-SEC-23c** | `api/user-signup/{id}` — **authenticated then unscoped** (chain B) | see D-027 |
| **G-AUTH-02** | Last two substring matchers → shared exact `role_key` gate | see D-029 |

### RE-GRANTS — as each fix lands

| Menus | State |
|---|---|
| Competency 154–158 | ✅ re-granted (`f9cd7ede`) — golden thread 1 demonstrable to an employee again |
| **Attendance 100** | ✅ **re-granted this turn.** Employee 17 → **18 leaves**, HRIT Management returns, nine roles still render |
| Attendance **101** | ❌ stays denied — different controller, **not assessed** |
| Leave 102/103/104 | ❌ stay denied — identity fixed, **row scope open** (`show():98` has no caller check, `index():40` is tenant-wide) |
| Payroll, Directory 22, Skill Gap 26, Talent, Reports, Task 210/212–215 | ❌ denied — see G-RBAC-01/02 |
| Dept Head + Reporting Manager | ⏸ **parked on reporting-line COVERAGE, not on a fix** (G-ORG-02) |

### SCHEDULED WITH TRIGGERS — not parked, not forgotten

| Item | Trigger |
|---|---|
| **Rename the Competency Library screen's vocabulary to Skill** — one pass (S) | **NEXT IN THE QUEUE** — item 9 is done, so this is no longer 'eligible', it is scheduled |
| **Semantic event/plan reconciliation** (G-RECON-01) — read each of the plan's ~45 items and ask what state change it implies | **when Tier 3 connection work starts** |
| Dept Head + Reporting Manager re-grants | reporting-line **coverage** (G-ORG-02) |
| `task.assigned`, `task.overdue` events | readiness gate: **task_hygiene** |

### HP BRAIN SEPARATION — Q-C4 CONFIRMED 2026-08-10, separation still LIVE

| Item | State |
|---|---|
| **C-SEP-01** — remove the cross-write + close the cross-product read leak | ✅ DONE (D-041). Both directions; `hpbrain_*` untouched |
| **C-SEP-02** — move 105 `hpbrain_*` tables out of `hp_erp` (**L**) | **Infrastructure. Flagged, not scheduled** |

`hpbrain_schema_migrations` (38 rows) still runs inside G2G's schema.

### PARKED — resume after foundations

| Item | Why parked |
|---|---|
| The `{id}` read probe | Real ids from tenant 7, read verbs only, chunked, one request per process |
| The **write-verb probe** | Needs the two-tenant precondition answered **and** an isolation approach approved before running. **The untested write half is 3 for 3 on real findings** |
| G-NAV-02 + nine-token harness | One-line fix; harness must change in the same commit or verification breaks silently |
| ~~`ResolvesLmsIdentity:101`, `LmsLearningController:1537`~~ | ✅ **DONE** — G-AUTH-02 |
| **SHAPE-02** — *a sentinel value is a schema saying something it was not designed to say* (`task_id = 0` meaning "configuration"). Filed with 1 instance; sweep on Triz's call | not swept |
| SHAPE-01 sweep | *"Trusted because of where it came from"* — filed with 2 instances, sweep on Triz's call |
| G-SEC-16 | **Capacity workstream, not security** — ~100 unbounded queries; heaviest six first, **plus `api/templates/{id}/versions` which returned 3.4 MB in ONE response** |
| F-05a / F-05b | `canAssign()` unused (G-ORG-01); no manager-assignment mechanism (G-ORG-02) |
| C24's write half | **772 write routes, NOT TESTED AT ALL.** Precondition: confirm it does not inherit C23's two-tenant blind spot |

**EXCEPTION:** only a **G-SEC-09-severity** finding jumps the queue.


### Still queued, not blocking

- **F-6** ontology iframe deletion (approved; needs the R8 checklist)
- **C37** — nine more hand-checks of C34's 114
- The **37** guard candidates, from `c23-result-FULL-912.json` — **do not re-run the guard**
- ~~C32~~ — subsumed by Slice 1's demo step 6
