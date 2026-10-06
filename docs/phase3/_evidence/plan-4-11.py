import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"
p = os.path.join(D, "08-connection-plan.md")
t = io.open(p, encoding="utf-8").read()
t = t.replace("**Status:** `DRAFT \u2014 \u00a71\u2013\u00a73 complete. \u00a74\u2013\u00a711 in assembly.`",
              "**Status:** `COMPLETE \u2014 \u00a71\u2013\u00a711. Awaiting approval. Nothing is built from this until approved.`")
t = t.replace("*\u00a74\u2013\u00a711 follow in the next assembly pass.*", "")

t += r"""
---

# §4. ORDERING WITHIN TIERS

**The rule, stated once so it is inspectable rather than asserted:**

> Within a tier, order by **DATA SENSITIVITY**:
> **1. candidate / personal → 2. payroll-adjacent → 3. credentials & integrations
> → 4. competency and learning content.**

**Why sensitivity and not route count:** route count optimises for closing many at
once. Sensitivity optimises for closing the worst first. `talent_interviewpanel`
has 5 routes and `assignmentController` has 19 — but interview panels hold
**candidate** records: people outside the company who never agreed to be in the
system. Once the Q-D4 portal exists that is external PII and a leak is a
**regulatory** matter, not only a commercial one.

**Where sensitivity does not apply** (Tier 1 migrations, Tier 2 mechanisms), order
by **what unblocks the most**. Tier 1's join tables land as **one migration**
because splitting them creates half-connected states nothing can use.

---

# §5. THE ITEM TABLE

**41 items.** Costs marked **ESTIMATE PENDING** where R7 files are not yet named —
R7 exists because L-01 was called XS until the options pipeline was opened.

**⚠️ ID collision fixed here:** the LMS write-up reused `L-01/L-02/L-03`, which
already belonged to Library & Taxonomy. **LMS items are renumbered `LM-*`.**

**Verification column:** `API` = I can run it · `SCREEN` = needs a person ·
`DB` = a query.

## Tier 0 — Security

| ID | Title | Gap | Thread | Cost (R7) | Blocks | Blocked by | Test | Verif | Status |
|---|---|---|---|---|---|---|---|---|---|
| S-01 | `talent_interviewpanelController` tenant fix | G-SEC-11 | 7 | **XS-S** · `talent_interviewpanelController.php` | nothing | \u2014 | AT-S01 | API | **NEXT** |
| S-02 | G-SEC-12 actor identity, 33 candidates | G-SEC-12 | 2, 9 | **ESTIMATE PENDING** \u2014 33 sites unclassified | **event store** | own classification | AT-S02 | API | Not started |
| S-03 | Remaining leaks, data-class order | G-SEC-11 | all | **ESTIMATE PENDING** | customer readiness | \u2014 | AT-S03 | API | Not started |
| S-04 | 37 guard candidates hand-verified | G-SEC-11 | \u2014 | **S** \u00b7 from `c23-result-FULL-912.json`, **no re-run** | S-03 scope | \u2014 | \u2014 | DB | Not started |
| S-05 | C37 ten checks (1 done) | \u2014 | \u2014 | **S** | C34 calibration | \u2014 | \u2014 | API | 1 of 10 |
| S-06 | C23 write-half phase | \u2014 | \u2014 | **ESTIMATE PENDING** | C24 gate | tenant + row register | \u2014 | API | Not started |
| S-07 | C23 regression guard in CI | G-QUAL-02 | \u2014 | **S** \u00b7 guard exists; needs a CI hook | prevents regrowth | \u2014 | \u2014 | API | Not started |
| S-08 | G-SEC-01 authorization coverage | G-SEC-01 | all | **ESTIMATE PENDING** \u2014 superseded counts | \u2014 | rights matrix | \u2014 | API | Not started |

## Tier 1 — Structural foundations

| ID | Title | Gap | Thread | Cost (R7) | Blocks | Blocked by | Test | Verif | Status |
|---|---|---|---|---|---|---|---|---|---|
| F-01 | **The five join tables, ONE migration** | G-DATA-06, G-FLOW-26 | 1-8 | **L** \u00b7 new migration + `02-domain-model.md` \u00a72.1 DDL | **everything in T3** | \u2014 | AT-F01 | DB | Not started |
| F-02 | `certification_type` + `certification_competency_map` | G-CERT-01 | 3, 8 | **M** \u00b7 \u00a710.1 DDL, steps 3b/9b | L-09, thread 8 | F-01 | AT-F02 | DB | Not started |
| F-03 | Three restored tables | Q-B5 | 2, 3, 8 | **S** \u00b7 \u00a74.2 idempotent DDL | evidence projector | \u2014 | AT-F03 | DB | Not started |
| F-04 | `skill_matrix_item` + `sub_institute_id` | G-DATA-08 | 1, 5, 6 | **M** \u00b7 step 12 normalisation | guards seeing tenancy | F-01 | AT-F04 | DB | Not started |
| F-05 | `reporting_manager_id` + `head_user_id` + cycle validation | Q-B1 | 2, 4, 9 | **M** \u00b7 `tbluser`, `hrms_departments` migration | every approval flow | \u2014 | AT-F05 | DB | Not started |
| F-06 | Tri-state rights columns | G-SEC-06 | 9 | **S** \u00b7 both rights tables | rights population | \u2014 | AT-F06 | DB | Not started |
| F-07 | Text\u2192FK migrations (steps 12-14) | G-DATA-06 | all | **L** \u00b7 backfill + report unmatched | joins by key | F-01 | AT-F07 | DB | Not started |
| F-08 | `portal_identity` | Q-D4 | 7 | **M** \u00b7 \u00a77.3 DDL | candidate conversion | \u2014 | AT-F08 | DB | Not started |
| F-09 | `library_map_skill` join table | G-DATA-07 | 1 | **S** \u00b7 3,270 rows to split | \u2014 | F-01 | AT-F09 | DB | Not started |

## Tier 2 — Mechanisms

| ID | Title | Gap | Thread | Cost (R7) | Blocks | Blocked by | Test | Verif | Status |
|---|---|---|---|---|---|---|---|---|---|
| X-01 | **Rights matrix populated** + before/after menu diff | G-SEC-07 | 9 | **L** \u00b7 seeder from `03-rbac-matrix.md` \u00a73.1-3.7 | X-02, thread 9 | F-06 | AT-X01 | **SCREEN** | Not started |
| X-02 | Route permission declarations | G-SEC-04 | all | **ESTIMATE PENDING** | authorization | X-01 | \u2014 | API | Not started |
| X-03 | **C19 picker mechanism** | G-LIB-08 | 1 | **M-L** \u00b7 `LibraryController` meta, `library-form.tsx`, `library-config.ts`, `services/competency/libraries.ts` | L-01/02/04 | \u00a710.0 *(decided)* | AT-X03 | SCREEN | Not started |
| X-04 | **Event store + projector/reactor split** | G-STR-04 | 2, 9 | **L** \u00b7 `05-data-flow-contracts.md` \u00a71 DDL | X-05, threads 2/9 | **S-02** | AT-X04 | DB | Not started |
| X-05 | `task_status_history` | G-STR-04 | 2 | **M** | thread 2 | X-04 | AT-X05 | DB | Not started |
| X-06 | Notification service + terminology | Q-F1 | 4, 9 | **M** \u00b7 \u00a78 DDL | readiness gates | \u2014 | AT-X06 | API | Not started |
| X-07 | Readiness gates + asymmetric switching | M1 | 6, 8 | **M** \u00b7 \u00a78 | honest surfaces | X-04 | AT-X07 | DB | Not started |
| X-08 | **Seed-library import flow** | G-FLOW-03, Q-C1 | 1, 6 | **L** \u00b7 \u00a79 | coverage | F-01 | AT-X08 | SCREEN | Not started |

## Tier 3 — Connections

| ID | Title | Gap | Thread | Cost (R7) | Blocks | Blocked by | Test | Verif | Status |
|---|---|---|---|---|---|---|---|---|---|
| L-01 | `department_id` from the Job Role form | G-LIB-01 | 1 | **XS** *(after X-03)* \u00b7 `library-config.ts` | \u2014 | X-03 | AT-L01 | SCREEN | Not started |
| L-02 | `department_id` from the Skill form | G-LIB-01 | 1 | **XS** *(after X-03)* | \u2014 | X-03 | AT-L02 | SCREEN | Not started |
| ~~L-03~~ | ~~Reach the detail panel~~ | G-LIB-06 | \u2014 | \u2014 | \u2014 | \u2014 | \u2014 | \u2014 | **SUPERSEDED by L-03R** |
| L-03R | Delete the dead panel | G-LIB-06 | \u2014 | **XS** | \u2014 | \u2014 | AT-L03R | SCREEN | \u2705 **BUILT** (D-001) |
| L-04 | Job Level \u2192 `s_level_responsibility` | G-LIB-07 | 1 | **XS** *(after X-03)* | \u2014 | X-03 | AT-L04 | SCREEN | Not started |
| L-05 | Honour Status at assignment time | G-LIB-05 | 3 | **S** \u00b7 `RoleMappingController`, `StudioController` | \u2014 | F-01 | AT-L05 | API | Not started |
| L-06 | Delete impact count / block | G-LIB-02 | \u2014 | **S** \u00b7 `library-tab.tsx` | \u2014 | \u2014 | AT-L06 | SCREEN | Not started |
| L-07 | Job Titles \u2192 `s_user_skill_jobrole` | G-LIB-02 | 1 | **M** | \u2014 | F-07 | AT-L07 | DB | Not started |
| L-08 | Learning Resources \u2192 course refs | G-FLOW-26 | 3, 4 | **M** | thread 4 | F-01 | AT-L08 | SCREEN | Not started |
| L-09 | Certifications \u2192 `certification_type` | G-CERT-01 | 3, 8 | **M** | thread 8 | F-02 | AT-L09 | SCREEN | Not started |
| L-10 | Import on every library tab | G-LIB-04 | 1 | **M** | \u2014 | X-08 | AT-L10 | SCREEN | Not started |
| L-11 | Join on ids, not titles | G-DATA-06 | all | **L** | \u2014 | *(= F-07)* | AT-F07 | DB | Not started |
| L-12 | One shared category table + applicability | G-LIB-02 | 1 | **L** | L-13, L-20 | F-01 | AT-L12 | DB | Not started |
| L-13 | Propagate taxonomy renames | G-LIB-02 | 1 | **M** | \u2014 | L-12 | AT-L13 | DB | Not started |
| L-14 | Task catalogue \u2192 competency | G-LIB-03 | 2 | **L** | thread 2 | *(= F-01)* | AT-F01 | DB | Not started |
| L-15 | Compliance Relevance \u2192 boolean + regulation ref | G-LIB-02 | 8 | **M** \u00b7 migration + `library-config.ts` | thread 8 | F-01 | AT-L15 | SCREEN | Not started |
| L-16 | Risk Implications \u2192 severity enum \u2192 `competency.criticality` | G-LIB-02 | \u2014 | **M** | \u2014 | F-01 | AT-L16 | DB | Not started |
| L-17 | `assessment_method` enum, **additive** | G-LIB-02 | 3 | **M** \u00b7 keeps both element columns | \u2014 | F-01 | AT-L17 | DB | Not started |
| L-18 | Importance \u2192 `competency_kasba_item.weight` | G-LIB-02 | 1 | **S** | \u2014 | F-01 | AT-L18 | DB | Not started |
| L-19 | Experience \u2192 numeric min years, text kept | G-LIB-02 | 6, 7 | **M** \u00b7 parse clear patterns, **report coverage** | thread 6 | F-01 | AT-L19 | DB | Not started |
| L-20 | Three `*_tags` \u2192 shared categories | G-LIB-02 | \u2014 | **S** | \u2014 | L-12 | AT-L20 | DB | Not started |
| L-21 | Performance Metrics on the rating screen | G-LIB-02 | 3 | **S** *(re-costed from display)* | \u2014 | F-01 | AT-L21 | SCREEN | Not started |
| L-22 | Measurement Metrics as scale anchor | G-LIB-02 | 3 | **S** *(re-costed)* | \u2014 | F-01 | AT-L22 | SCREEN | Not started |
| L-23 | Development Methods at plan creation | G-LIB-02 | 4 | **S** *(re-costed)* | \u2014 | F-01 | AT-L23 | SCREEN | Not started |
| C-10 | Library drawer: 5 unrendered fields | \u2014 | \u2014 | **display** \u00b7 data already on the wire | \u2014 | \u2014 | AT-C10 | SCREEN | Not started |
| M-01 | Learning edit controls | G-FLOW-26 | 4 | **XS** \u00b7 endpoint already accepts both | \u2014 | \u2014 | AT-M01 | SCREEN | Not started |
| M-02 | Learning assignment records its gap | G-FLOW-26 | 4 | **M** | thread 4 | F-01 | AT-M02 | SCREEN | Not started |
| M-03 | **Role-mapping create path** + reinstate the button | **G-MAP-01** | 1 | **S-M** \u00b7 surface `SchoolSetupController.php:392-408` | thread 1 | F-01 | AT-M03 | SCREEN | Not started |
| M-04 | `skill_matrix_item` tenant column | G-DATA-08 | 1, 5 | **XS** *(inside F-04)* | \u2014 | *(= F-04)* | AT-F04 | DB | Not started |
| O-01 | Skill Deficit KPI honesty | G-DATA-05 | \u2014 | **XS** \u00b7 `employee-directory.tsx` | \u2014 | \u2014 | AT-O01 | SCREEN | Not started |
| O-02 | Directory ratings via one service | \u2014 | 1 | **S** | \u2014 | F-04 | AT-O02 | API | Not started |
| O-03 | `ExcelAutomationAgentController@credentialStatus` | G-SEC-11 | \u2014 | **XS-S** | \u2014 | \u2014 | AT-O03 | API | Not started |
| O-04 | Three report-route leaks | G-SEC-11 | \u2014 | **S** | \u2014 | \u2014 | AT-O04 | API | Not started |
| O-05 | Read `HrmsController` (31 routes) | C21 | \u2014 | **S** *(reading)* | S-03 scope | \u2014 | \u2014 | \u2014 | Not started |
| LM-01 | Retire `contentLibraryControllerOld` | \u2014 | \u2014 | **S** \u00b7 \u26a0\ufe0f **R8 + approval** | \u2014 | \u2014 | AT-LM01 | API | Not started |
| LM-02 | Course Builder prompt enrichment | \u2014 | 4 | **XS** \u00b7 `course-builder-panel.tsx` | \u2014 | \u2014 | AT-LM02 | SCREEN | Not started |
| LM-03 | Surface the LMS funnel | G-FLOW-05 | 4 | **S** | \u2014 | \u2014 | AT-LM03 | SCREEN | Not started |
| T-01 | One write path for `task.status` | S-6 | 2 | **M** \u00b7 10 writing files | \u2014 | S-6 verification | AT-T01 | API | Not started |
| T-02 | Surface `delay_category` | G-FLOW-24 | 2 | **XS** \u00b7 mechanism already correct | \u2014 | \u2014 | AT-T02 | SCREEN | Not started |
| TL-01 | *(= S-01)* interview panel leak | G-SEC-11 | 7 | **XS-S** | \u2014 | \u2014 | AT-S01 | API | **NEXT** |
| TL-02 | Performance goal \u2192 `competency_id` | **G-FLOW-26** | 5 | **M** \u00b7 `PerformanceGoalController.php` | 9-box | F-01 | AT-TL02 | DB | Not started |
| TL-03 | Requisitions read `jobrole_competency_map` | **G-FLOW-26** | 7 | **M** | thread 7 | F-01 | AT-TL03 | SCREEN | Not started |
| TL-04 | Resolve the two `OnboardingTaskController`s | \u2014 | \u2014 | **S** \u00b7 read both first (R6) | \u2014 | \u2014 | AT-TL04 | API | Not started |

## Tier 4 — Surfaces

| ID | Title | Gap | Thread | Cost (R7) | Blocks | Blocked by | Test | Verif | Status |
|---|---|---|---|---|---|---|---|---|---|
| R-01 | Consolidated reporting home | Q-A4 | \u2014 | **ESTIMATE PENDING** | \u2014 | F-01, F-02 | AT-R01 | SCREEN | Not started |
| R-02 | Competency gap report | \u2014 | 1 | **M** | \u2014 | R-01 | AT-R02 | SCREEN | Not started |
| R-03 | Development plan report | \u2014 | 4 | **M** | \u2014 | R-01, M-02 | AT-R03 | SCREEN | Not started |
| R-04 | Certification expiry report | G-CERT-01 | 8 | **M** | \u2014 | R-01, F-02 | AT-R04 | SCREEN | Not started |
| R-05 | 9-box second axis | **G-FLOW-26** | 5 | **M** | \u2014 | TL-02 | AT-R05 | SCREEN | Not started |

### \u00a75.1 \u2014 whole-plan reconciliation

**Run across the plan, not per module.** Items appearing in several write-ups
appear **once** here.

| | Count |
|---|---:|
| Items deduplicated into one row | **4** \u2014 L-11 = F-07 · L-14 = F-01 · M-04 = F-04 · TL-01 = S-01 |
| ID collisions fixed | **3** \u2014 LMS `L-01/02/03` \u2192 `LM-01/02/03` |
| Superseded | **1** \u2014 L-03 by L-03R |
| **ESTIMATE PENDING** | **6** \u2014 S-02, S-03, S-06, S-08, X-02, R-01 |
| Already built | **1** \u2014 L-03R |

> **The headline result of Gate C, stated as one:** across six modules, \u00a75.1
> returned **2 new / 3-5 already approved** every time. **Gate C found almost
> nothing that Gate B's domain model had not already anticipated.** That is what a
> correct model looks like, and it is the strongest evidence that
> blueprint-before-code was the right sequencing.

---

# §6. SLICES

**Phase 3 must not be one long invisible build.** Five slices, each ending in
something demonstrable.

---

## SLICE 1 \u2014 "One job role, one employee, one visible gap"

**The shortest path to a visible capability chain.** Triz's assumption was seed
import + join tables + one role's mapping + a gap on a profile. **That is right,
with one correction: the seed import is not needed for Slice 1** \u2014 the mapping
rows already exist (79,295 of them). Importing is how you get *coverage*; Slice 1
only needs *one role*.

### Items, in order

| # | Item | Why it is in Slice 1 |
|---|---|---|
| 1 | **S-01** interview panel leak | Tier 0. Nothing demos until security items in flight are closed |
| 2 | **F-01** the five join tables, one migration | The chain has no shape without them |
| 3 | **F-04** `skill_matrix_item` + tenant column | The measured side of the gap |
| 4 | **F-07** text\u2192FK, scoped to **one job role** | Full backfill is not required to demo one role |
| 5 | **M-03** role-mapping create path | So the mapping is *authorable*, not just seeded |
| 6 | A gap read: required vs measured, on the employee profile | The visible output |

**Deliberately NOT in Slice 1:** the rights matrix, the event store, the picker
mechanism, the import flow, every report. **None is needed to show the chain.**

### Demo script \u2014 "Staff Nurse"

1. Open **Competency \u2192 Library & Taxonomy \u2192 Job Role**, show *Staff Nurse* exists.
2. Open **Framework & Role Mapping**, add three competencies at required proficiency \u2014 **using M-03's create path, not cell-by-cell**.
3. Open **Organization \u2192 Employee Directory**, pick an employee whose role is *Staff Nurse*.
4. Rate them on one of the three competencies.
5. Open their **capability profile**: **required 3, measured 1, gap 2** \u2014 resolved **by key**.
6. Rename the job role to *Registered Nurse*. **The mapping survives.** *(Today it silently detaches \u2014 this is the moment that shows what was fixed.)*

### What a user can do at the end
Define what a role requires, measure a person against it, and **see the gap** \u2014
for one role, end to end.

### What is still missing
Coverage (one role, not all), learning assignment, evidence from tasks,
reports, and the rights matrix \u2014 so **everyone still sees everything**.

### How far away
**Honestly: F-01 + F-04 + F-07 are the largest single migration in the plan
(two L's and an M), and F-07 is a backfill against 283,126 string-joined rows even
scoped to one role.** Slice 1 is **not a quick win** \u2014 it is the *shortest* path,
which is not the same thing. Everything after it is faster, because the foundation
is laid once.

---

## SLICE 2 \u2014 "Roles mean something"

**Items:** F-06 tri-state rights \u2192 **X-01 rights matrix populated with the
before/after menu diff** \u2192 X-02 route permission declarations \u2192 S-08.

**Demo:** log in as Employee, then as HR, then as Admin \u2014 **three different
products**. Today all three see essentially the same 1,500-1,657 menus, with
Employee seeing *more* than Admin.

**Still missing:** the capability chain is one role deep; no learning loop.
**Gate:** X-01 needs the before/after diff reviewed by Triz before rollout.

---

## SLICE 3 \u2014 "Work and learning feed capability"

**Items:** S-02 (G-SEC-12) \u2192 X-04 event store \u2192 X-05 `task_status_history` \u2192
F-03 restored tables \u2192 `competency_evidence` projector \u2192 M-02, M-01.

**Demo:** complete a job-role task \u2192 evidence appears against the competency it
exercises \u2192 a gap triggers a learning assignment **recorded against that gap** \u2192
completion raises proficiency where policy allows.

**This is the loop the product is sold on, closed for the first time.**

**Still missing:** recruitment, performance, reports.
**Note:** S-02 first is not optional \u2014 an event store on untrustworthy `actor_id`
inherits a corrupted audit trail on day one.

---

## SLICE 4 \u2014 "The rest of the chain"

**Items:** F-02 + L-09 certifications \u2192 F-05 reporting line \u2192 TL-02 performance \u2192
TL-03 recruitment \u2192 F-08 `portal_identity` \u2192 X-03 picker \u2192 L-01/02/04.

**Demo:** a requisition generating its scorecard from the framework; a review whose
goal points at a real competency; a certificate resolving to a known type.

**Still missing:** reports, and the candidate portal *(deferred by design)*.

---

## SLICE 5 \u2014 "See it"

**Items:** X-08 import \u2192 R-01 reporting home \u2192 R-02/03/04 \u2192 R-05 9-box \u2192 O-01.

**Demo:** a new tenant imports a seed library and **sees a populated product on day
one**; the three reports that never existed among the 45 legacy ones; a 9-box with
**both axes**.

**Still missing:** nothing in Phase 3 scope.

---

## Slice summary

| Slice | Ends with | Visible? |
|---|---|---|
| 1 | one role's gap, resolved by key, surviving a rename | **yes** |
| 2 | three roles seeing three different products | **yes** |
| 3 | the capability loop closed | **yes** |
| 4 | recruitment, performance, certifications joined | **yes** |
| 5 | populated reports and a working 9-box | **yes** |

---

# §7. THE SECURITY QUEUE

Folded in as a Tier 0 sub-plan. Order is **§4's data-class rule**, not route count.

| # | Item | Note |
|---:|---|---|
| 1 | **`talent_interviewpanelController`** | **candidate data.** Confirmed leak, executed |
| 2 | The other three C27 `talent_*` controllers | trait present, still reading from the request |
| 3 | **G-SEC-12's 33 candidates** | hand-classify IDENTITY vs SUBJECT, then `payrollActorId()` shape. **Blocks the event store** |
| 4 | Payroll-adjacent leaks | Leave and report routes |
| 5 | `ExcelAutomationAgentController@credentialStatus` | another tenant's integration credentials |
| 6 | Competency / learning leaks | `skillcontroller`, `assignmentController`, rest |
| 7 | **37 guard candidates** | from `c23-result-FULL-912.json`. **Do not re-run the guard** |
| 8 | **C37's nine remaining checks** | calibrate C34 or close it as a proven negative |
| 9 | **C23 write-half** | per controller, opt-in, every row registered |
| 10 | **C23 regression guard in CI** | without it this regrows on the next controller |

> ## \u26d4 C24 \u2014 RELEASE PRECONDITION
> **No customer tenant is created on this platform until the tenant-isolation test
> suite passes end to end.** A business rule, not an engineering task. The gate is
> a **passing suite**, not a completed fix \u2014 because C22 showed a fix can look done
> and not be.

---

# §8. CONTINGENT ITEMS

**A plan that hides its own uncertainty is worse than one that shows it.**

| Item | Depends on | If it comes back differently |
|---|---|---|
| **S-03** remaining leaks | **S-04**'s classification of 37 candidates | If a **fourth defect class** appears (not wrong-scope, not no-scope, not actor), Tier 0 grows and Slice 1 slips |
| **C34's 114** | **S-05** \u2014 C37's ten checks | **One real hit** \u2192 C34 calibrates and 114 become a worklist. **Ten false positives** \u2192 C34 closes as a **proven negative** and the no-scoping class does not exist here. Either ends it |
| **S-02** G-SEC-12 | its own hand-classification | If most of the 33 are SUBJECT, this is small. If most are IDENTITY, it is **M-L** and the event store slips |
| **X-03** picker | actual cost of the meta pipeline | If it exceeds M-L, L-01/02/04 stay XS but arrive later |
| **X-08** import | whether `SchoolSetupController`'s bulk path generalises | If it does not, the import is **L** and Slice 5 slips |
| **F-07** text\u2192FK | how many rows fail to match on backfill | **Unmatched rows are reported, never guessed** (\u00a710.0). A high unmatched rate means manual reconciliation |
| **L-17** | whether the two element columns are a controlled vocabulary | Already measured: **10 terms cover 78%** \u2014 it is a library. Additive, not a substitution |
| **F-02** certifications | whether §10 gained a step | **Confirmed a genuine Gate B omission**; steps 3b/9b added |

---

# §9. NOT IN PHASE 3

**This section exists so nobody later mistakes a decision for an oversight.**

| Item | Reason | Ref |
|---|---|---|
| **CRM** (Marketing, Leads, Master Fields) | Deferred. Code and data intact, hidden from nav, no Phase 3 work | Q-A4 |
| **Applicant-facing candidate portal** | Phase 3 defines the identity model, isolation boundary and conversion step. **Building the portal is a separate deliverable** | Q-D4 |
| **External trainer / vendor identities** | Same pattern as Candidate, deferred. The model must generalise (`portal_identity` + type discriminator) but they are not designed now | Q-D4 |
| **Delegation / acting manager** | Not Phase 3 build work. Two rules designed in now: audit records both parties; delegation never widens scope | A4 |
| **Leave convergence steps 3-4** | Fold local leave flags into the shared rights matrix, then drop `hrms_leave_role_permissions`. First post-Phase-3 items | A7 |
| **27 deferred nav rows** | Itemised in `01b-scope-triage.md` | Q-A3 |
| **Compensation** | Not in the golden threads | \u2014 |
| **Template management** | Not in the golden threads | \u2014 |
| **65 nav rows marked DELETE** | Approved in principle; **no row removed without a reversible script + backup** | Q-A3 amendment 4 |
| **The 9-box surface** *(if T4 defers)* | Thread 5's data lands in Phase 3; the grid itself may be Phase 4 | \u00a72 |

---

# §10. DEFINITION OF DONE

**Phase 3 is complete when all four hold:**

1. **All Tier 0 and Tier 1 items shipped and verified.**
2. **Every golden thread either works end to end or is explicitly deferred with a reason recorded.**
3. **The C23 tenant-isolation suite is green, including the write half.**
4. **One customer-ready demonstration of the capability chain exists** \u2014 Slice 1's demo script, run for real.

**Anything beyond that is Phase 4.**

**Adopted as proposed.** The evidence does not contradict it, and two points
support it: Tier 2's mechanisms are *enabling*, not *demonstrable* \u2014 tying "done"
to them would hide progress; and item 3 is the only one with an objective pass/fail
that a person cannot talk their way past.

---

# §11. RISKS

| # | Risk | Impact | Signal it is happening |
|---:|---|---|---|
| 1 | **The 37 unverified candidates turn up a fourth leak class** | Tier 0 grows; Slice 1 slips | S-04 finds a failure that is neither wrong-scope, no-scope nor actor |
| 2 | **X-03 picker exceeds M-L** | L-01/02/04 slip; no other item blocked | The meta pipeline needs restructuring rather than extending |
| 3 | **The seed import is harder than `SchoolSetupController` suggests** | Slice 5 slips; **coverage stays at 2.7%** | The bulk path is signup-specific and does not generalise |
| 4 | **The 75 uncommitted Phase 1/2 files are lost or diverge** | **The F-01 tenant-resolution work itself is in that set.** Losing it silently reverts D-003/D-004 | Escalated to Triz; outside my control |
| 5 | **F-07's backfill leaves a high unmatched rate** | Manual reconciliation per tenant | Unmatched report is large after the dry run |
| 6 | **The rights matrix diff removes screens people rely on** | Rollout blocked | Triz's per-role review finds losses \u2014 **which is why the review exists** |
| 7 | **Process regrowth** | The failure mode of the last ten turns | New rules or numbered checks appear without one being retired |

---

**END OF PLAN. Awaiting approval. Nothing is built from this until approved.**
"""
io.open(p, "w", encoding="utf-8").write(t)
print("plan sections 4-11 appended; bytes:", len(t.encode("utf-8")))
