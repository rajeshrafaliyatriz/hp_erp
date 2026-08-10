# 08 — Connection Plan

**The deliverable the Phase 3 brief asked for.** Assembled from the CONNECTIONS TO
BUILD sections of the six module write-ups. Nothing is re-derived here.

**Status:** `COMPLETE — §1–§11. Awaiting approval. Nothing is built from this until approved.`

⚠️ **Headline numbers corrected by `12-gate-c-verification.md` V6** — **283,126** (rows with a populated key; 283,127 rows exist), 2.7% (not 3.0%), 29 controllers (not 30). The write-route coverage claim is **withdrawn** pending re-derivation.
**Nothing is built from this document until it is approved.**

---

## 0. Gate C coverage — stated plainly before anything else

**30 of 32 sub-modules audited.** The other two, named:

| Row | Status |
|---|---|
| **CRM** (Marketing / Leads / Master Fields) | **DELIBERATELY OUT OF SCOPE.** Q-A4 deferred it: code and data intact, hidden from navigation, no Phase 3 design or connection work. **Recorded, not audited** — a decision, not an omission |
| **"Development & Career Paths"** (plural) | **A DUPLICATE CHECKLIST ROW.** The same screen as "Development & Career Path" (singular), which *was* audited — and calibrated at 0 errors over 206 rows (C1b). Two `_raw-inventory` files describe one screen |

**Neither was "not reached."** One is a scope decision, one is a bookkeeping
artefact in my own checklist.

⚠️ **A count correction while stating this:** the module checklist carries **11**
Competency rows, and my write-up counted **9**. The difference is the duplicate
above plus *Skill Taxonomy* and *Taxonomy Ontology* being covered as one section.
**No sub-module was skipped**, but "9" understated the checklist. The reconciled
figure is **30 audited · 1 duplicate · 1 out of scope = 32**.

---

# §1. DIAGNOSIS

*Written for someone who has never opened the code.*

## The product does two things wrong, and they are opposites

This system is a Competency and HR platform. Its promise is a chain: **a job role
needs certain capabilities → we measure whether an employee has them → we close the
gap with learning → we prove it happened.**

That chain is broken in two different ways at once.

### The supply side — what exists is held together by names, not keys

> **283,126 rows** carry the product's core relationships — *which job role needs
> which skill*, *which tasks belong to which role* — and **every one of them is
> resolved by matching a piece of text**, not by a proper database link.

*What this means in practice:* if someone renames a job role from "Staff Nurse" to
"Registered Nurse", every task and every skill attached to it **silently stops
matching**. Nothing errors. Nothing warns. The data is simply orphaned.

**Caveats, which travel with the number:**
- **Not 283,126 defects.** It is 283,126 rows across four tables, each resolving its relationship by string.
- **The data is not wrong today.** It means any rename detaches it and nothing can join by key.
- This is **test data** — the *structure* is the finding, not the volume. A customer's own library would have the same shape.

### The demand side — three connections the product is sold on were never built

> **The words are there. The connections are not.**

| Sold as | What actually exists |
|---|---|
| *"Performance reviews informed by competency"* | the word **"competency"** as a dropdown label and a validator value. **No link to any competency record** |
| *"Recruit against your competency framework"* | **nothing.** Recruitment has never referenced the framework |
| *"Close skill gaps with targeted learning"* | a **free-text notes field** called *Learning Resources* |

### The single illustration: the 9-box grid

The 9-box grid is a standard HR tool: **performance on one axis, potential or
capability on the other.**

> **This product's 9-box has performance on one axis and nothing to put on the
> other.** Performance has never been able to read a capability measurement,
> because the join was never built.

**That is the whole diagnosis in one screen.**

**One line, because someone will ask:** the headline counts **rows with a populated
string key — 283,126**. The four tables hold **283,127 rows**; one row of
`s_user_jobrole_task` has an empty `jobrole`. *Rows* and *rows with a key* are
different claims; the headline is the second.

## The three supporting numbers

| Number | What it is | Caveats that must travel with it |
|---|---|---|
| **283,126** | rows whose relationships resolve by string, across four tables verified individually | not defects; not a column count; test data; the structure is the finding |
| **3 confirmed cross-tenant leaks** — 2 fixed | one organisation could read another's data. `skillLibraryController` and `PayrollController` **are fixed and verified green**; `talent_interviewpanelController` remains | **A floor, not a total.** 46 route failures remain unverified candidates; 454 routes could not be tested; the write half is untested |
| **2.7%** | share of active users with any capability measurement — **7 of 264** | **test data (R2).** It measures reach, not quality; all 7 resolve cleanly |

**And the fact that changes everything about urgency:** **there are no customers.**
This is a test database with no production tenant. Three times in this phase that
fact converted a catastrophe into a cheap fix. **It is a depreciating asset** — and
it is the entire argument for finishing the foundations before the first client.

---

# §2. THE GOLDEN THREADS, TRACED

*This is the most important section. Each thread reads as "here is how it becomes
real", not as a list of tickets.*

Legend — **T0/T1/T2/T3/T4** are the dependency tiers defined in §3.

---

## Thread 1 · A new employee gets a capability profile

**Current state:** an employee can be created and given a job role, and **the chain
stops there** — nothing resolves what that role requires, so there is no gap to
show. `s_user_skill_jobrole` (79,295 rows) has **no create path in the UI at all**
(G-MAP-01); its rows arrived via tenant provisioning.

**Ordered items:**

| # | Item | Tier |
|---|---|---|
| 1 | `competency` + `competency_kasba_item` tables | T1 |
| 2 | `jobrole_competency_map` | T1 |
| 3 | Text→FK migration on `s_user_jobrole` / `s_users_skills` (L-11) | T1 |
| 4 | `skill_matrix_item` with `sub_institute_id` (M-04) | T1 |
| 5 | Seed-library import flow (§9 of the domain model) | T2 |
| 6 | **M-03** — a create path for role mapping, surfacing the existing bulk insert | T3 |
| 7 | **L-01 / L-02** — `department_id` written by the library forms | T3 |

**When complete, a user can:** create an employee, assign a job role, and
**immediately see the capabilities that role requires and which the employee is
missing** — with the gap computed from real keys, not name matching.

**Phase 3 completable:** ✅ **yes.**

---

## Thread 2 · Work performed becomes a capability signal

**Current state:** **half-built, and the half that exists is weak.** `task.skill_id`
is populated on **1,514 of 2,271 tasks (66.7%)** — but it is hand-picked by whoever
created the ticket. The **catalogue** (`s_user_jobrole_task`, 85,663 rows) has **no
competency link at all**.

**Ordered items:**

| # | Item | Tier |
|---|---|---|
| 1 | `jobrole_task_competency_map` (Q-C3) | T1 |
| 2 | `s_user_jobrole_task.jobrole_id` FK, backfill, drop the text key | T1 |
| 3 | **G-SEC-12** — actor identity trustworthy | T0 |
| 4 | Event store + projector/reactor split | T2 |
| 5 | `task_status_history` as the store's first consumer | T2 |
| 6 | `competency_evidence` projector reacting to task outcomes (Q-B3) | T2 |
| 7 | **T-01** — one write path and one owner for `task.status` | T3 |

**When complete, a user can:** complete a job-role task and have it **recorded as
evidence toward the competency that task exercises** — at role level, not per-ticket
guesswork. A manager sees "3 failures in 90 days on the same task" as a flag
(Q-B3), and **proficiency never auto-lowers**; it changes only on explicit manager
confirmation.

⚠️ **The overdue/stall rule cannot rely on `delay_category`** — one task of 2,271
has ever reached `ON HOLD`, so the column is empty for want of the state, not for
want of a writer (G-FLOW-24).

**Phase 3 completable:** ✅ **yes, but gated on T0.** The event store cannot be
trusted until G-SEC-12 closes.

---

## Thread 3 · Capability is proven

**Current state:** **broken at three joints.** Courses have no competency link
(G-DATA-01); Competency's *Learning Resources* is free text (L-08); and **there is
no certification TYPE entity at all** (G-CERT-01) — every credential is an
independent string, so no coverage or expiry roll-up can be trusted.

**Ordered items:**

| # | Item | Tier |
|---|---|---|
| 1 | `certification_type` + `certification_competency_map` (§10.1, steps 3b/9b) | T1 |
| 2 | `course_competency_map` (Q-B4) | T1 |
| 3 | `competency_evidence` restored | T1 |
| 4 | Backfill `certification_type_id`, **reporting unmatched rather than guessing** | T2 |
| 5 | **L-08 / L-09** — Learning Resources and Certifications become references | T3 |

**When complete, a user can:** upload an external certificate, have it **resolve to
a known certification type**, and see the competencies it evidences at a stated
proficiency — and the organisation can count who holds what and what expires when.

**Phase 3 completable:** ✅ **yes.**

---

## Thread 4 · A gap becomes learning, and learning closes the gap

**Current state:** **the loop does not close.** A development plan can be created,
learning assigned and progress tracked — and **none of it is attributable to a
measured gap.** `AssignLearningForm` has no competency selector. Separately, the
LMS funnel has collapsed: **1,426 enrolments, 1 content-progress row, 0
certificates** — and zero certificates is the *correct* output, because the gate has
never been satisfiable.

**Ordered items:**

| # | Item | Tier |
|---|---|---|
| 1 | `course_competency_map` | T1 |
| 2 | **M-02** — a learning assignment records the gap it closes | T3 |
| 3 | **M-01** — edit controls for `assignment_type` / `due_date` (the endpoint already accepts both) | T3 |
| 4 | Q-B2 gate: completion raises proficiency only where the tenant allows and the competency does not require assessment | T2 |
| 5 | **L-03** — surface the funnel: enrolled → started → completed → certified | T4 |

**When complete, a user can:** see a gap, assign learning **against that gap**, and
have completion raise the measured proficiency where policy allows — with the
funnel visible so a collapse like the current one is noticed.

**Phase 3 completable:** ✅ **yes.**

---

## Thread 5 · Performance and capability meet

**Current state:** **not built.** "Competency" exists in Performance only as a
validator enum value and a filter label. **No join to any competency table exists
anywhere in `Api/Performance/`.** The 9-box has nothing for its second axis.

**Ordered items:**

| # | Item | Tier |
|---|---|---|
| 1 | `competency` table | T1 |
| 2 | `skill_matrix_item` with tenant column | T1 |
| 3 | **TL-02** — goal `category='competency'` gains a real `competency_id` | T3 |
| 4 | 9-box reads measured capability on its second axis | T4 |

**When complete, a user can:** run a review where a goal **points at a real
competency**, and see a 9-box with performance against **measured capability**.

**Phase 3 completable:** ⚠️ **partially.** Items 1–3 yes. **Item 4 — the 9-box
itself — is a reporting surface (T4) and may land in Phase 4** if the tier-4 work
is deferred. *What is missing:* nothing structural; only the surface.

---

## Thread 6 · Internal mobility and succession

**Current state:** mobility screens are live; **eligibility cannot be computed**,
because required capability is not resolvable and *Experience* is free text
(*"2-3 years in trade processing…"*).

**Ordered items:**

| # | Item | Tier |
|---|---|---|
| 1 | `jobrole_competency_map` | T1 |
| 2 | `skill_matrix_item` | T1 |
| 3 | **L-19** — `min_years_experience` derived where parseable, **unmatched reported, text retained** | T3 |
| 4 | Readiness gates (`02-domain-model.md` §8) so eligibility declares its own completeness | T2 |

**When complete, a user can:** shortlist internal candidates for a role **by
measured capability against that role's requirements**, with coverage stated rather
than assumed.

**Phase 3 completable:** ✅ **yes** — with the caveat that eligibility quality
depends on capability coverage, currently **2.7%**. The seed import is what moves
that.

---

## Thread 7 · Recruitment from the framework

**Current state:** **not built.** Q-D1 recorded that Recruiter *retains read of
job-role competency requirements so requisitions and scorecards generate from the
framework.* **Nothing in `Api/Talent/` or `talent_*` references any competency
mapping table.**

**Ordered items:**

| # | Item | Tier |
|---|---|---|
| 1 | `jobrole_competency_map` | T1 |
| 2 | **TL-03** — requisitions and scorecards read it | T3 |
| 3 | **TL-01** — fix `talent_interviewpanelController`'s tenant leak **first** (candidate data) | T0 |
| 4 | `portal_identity` model + conversion step (Q-D4) | T1 |

**When complete, a user can:** raise a requisition that **generates its scorecard
from the role's competency framework**, and convert a hired candidate into an
employee whose capability profile starts from that same framework.

**Phase 3 completable:** ⚠️ **partially.** The framework read and the identity
model, yes. **The applicant-facing portal is explicitly deferred scope** (Q-D4) —
*what is missing:* the candidate-facing product, which is a separate deliverable.

---

## Thread 8 · Compliance is demonstrable

**Current state:** **blocked by the same missing type entity as thread 3.** A
regulatory requirement can be recorded, but coverage cannot be counted because two
employees holding the same real certification are two unrelated strings.

**Ordered items:**

| # | Item | Tier |
|---|---|---|
| 1 | `certification_type` + `certification_competency_map` | T1 |
| 2 | `competency_certification_requirements` restored | T1 |
| 3 | **L-15** — *Compliance Relevance* becomes a **boolean + regulation reference**, informing the `requires_assessment` default | T3 |
| 4 | Certification expiry report | T4 |

**When complete, a user can:** state *"this role requires this certification"*, see
**who holds it, who is expiring, and who never had it** — and export that as
evidence.

**Phase 3 completable:** ⚠️ **items 1–3 yes; item 4 is T4.** *What is missing if T4
defers:* the report surface only — the data becomes correct either way.

---

## Thread 9 · Offboarding closes the loop

**Current state:** offboarding is live and has its own manager field. **Access
revocation depends on the rights matrix**, which currently *carries no information*:
`can_view` = 1 on all 4,879 rows, every other action 0, and Employee sees **more**
menus than Admin (G-SEC-07).

**Ordered items:**

| # | Item | Tier |
|---|---|---|
| 1 | Tri-state rights columns (G-SEC-06) | T1 |
| 2 | **Populate the rights matrix** from `03-rbac-matrix.md` §3.1–3.7, with a per-role before/after menu diff for review | T2 |
| 3 | Event store, so revocation is auditable | T2 |
| 4 | Offboarding reactor: reassign open tasks, close learning, revoke access, archive | T3 |

**When complete, a user can:** offboard someone and have **open tasks reassigned,
learning closed, access actually revoked, and the whole sequence auditable.**

**Phase 3 completable:** ✅ **yes** — and it is the thread most dependent on T2's
rights work, which is also the highest-risk item in the plan (§11).

---

## Thread summary

| Thread | Completable in Phase 3 | If not, what is missing |
|---|---|---|
| 1 · capability profile | ✅ | — |
| 2 · work → signal | ✅ *(gated on T0)* | — |
| 3 · capability proven | ✅ | — |
| 4 · gap → learning | ✅ | — |
| 5 · performance ↔ capability | ⚠️ partial | the 9-box **surface** (T4) |
| 6 · mobility / succession | ✅ | coverage quality depends on the import |
| 7 · recruitment | ⚠️ partial | the **candidate portal** — deferred by Q-D4 |
| 8 · compliance | ⚠️ partial | the expiry **report** (T4) |
| 9 · offboarding | ✅ | — |

**Six of nine complete end to end. Three partial, and in every case what is missing
is a surface or a deliberately deferred product — never a broken foundation.**

---

# §3. DEPENDENCY TIERS

**Rule: an item may only depend on items in the same or a lower tier.** Where that
is violated, it is called out.

## Tier 0 — SECURITY · *nothing ships to a customer until these close*

| Item | Blocks | Blocked by |
|---|---|---|
| **TL-01** `talent_interviewpanelController` tenant leak | nothing structurally — **but it is candidate PII** | nothing. **Do first** |
| **G-SEC-12** caller-supplied audit provenance (33 candidates) | **the event store** (T2), therefore threads 2 and 9 | its own hand-classification |
| Remaining tenant leaks, data-class order (§4) | customer readiness | nothing |
| **C37** — ten hand-checks of C34's 114 | whether a fourth defect class exists | nothing |
| The 37 unverified guard candidates | the true size of the leak surface | reading them |
| **C23 write-half** phase | C24's release gate | tenant + row register |
| **C23 regression guard in CI** | prevents regrowth | the guard itself (exists) |

> ### ⛔ C24 — RELEASE PRECONDITION
> **No customer tenant is created on this platform until the tenant-isolation suite
> passes end to end.** A business rule, not an engineering task. The gate is a
> **passing suite**, not a completed fix — because C22 showed a fix can look done
> and not be.

## Tier 1 — STRUCTURAL FOUNDATIONS · *nothing connects without them*

| Item | Blocks | Blocked by |
|---|---|---|
| The five join tables (`competency`, `competency_kasba_item`, `jobrole_competency_map`, `course_competency_map`, `jobrole_task_competency_map`) | **threads 1–8** | nothing — **one migration, land together** |
| `certification_type` + `certification_competency_map` (3b) | threads 3, 8 | nothing |
| Three restored tables (`competency_evidence`, `competency_certification_requirements`, `s_skill_jobrole`) | threads 2, 3, 8 | nothing |
| `skill_matrix_item` + **`sub_institute_id`** (M-04) | threads 1, 5, 6; **and the guards' ability to see tenancy at all** | the normalisation migration (step 12) |
| `tbluser.reporting_manager_id`, `hrms_departments.head_user_id` | every approval flow; threads 2, 4, 9 | nothing |
| Tri-state rights columns (G-SEC-06) | rights population (T2), thread 9 | nothing |
| Text→FK migrations (L-11; steps 12, 13, 14) | **everything** — this is G-DATA-06's fix | the join tables existing first |
| `portal_identity` (Q-D4) | thread 7's conversion step | nothing |

## Tier 2 — MECHANISMS

| Item | Blocks | Blocked by |
|---|---|---|
| **Populate the rights matrix** (§4.5, with before/after diff) | thread 9; all real authorization | tri-state columns (T1) |
| Route permission declarations (G-SEC-04) + its regression guard | authorization coverage | rights matrix |
| **C19 picker mechanism** | L-01, L-02, L-04, and every later entity binding | §10.0 binding rule *(decided)* |
| **Event store + projector/reactor split** | threads 2, 9; `task_status_history`; `competency_evidence` | **G-SEC-12 (T0)** |
| `task_status_history` | thread 2 | event store |
| Notification service + terminology tables (Q-F1) | readiness gates, approvals | nothing |
| Readiness gates + asymmetric switching | threads 6, 8 honesty | event store |
| **Seed-library import flow** | threads 1, 6 *(coverage)* | the join tables |

## Tier 3 — CONNECTIONS

Everything from the CONNECTIONS TO BUILD sections: **L-01…L-23, M-01…M-04,
O-01…O-05, T-01, T-02, TL-01…TL-04**. Enumerated with costs in §5.

## Tier 4 — SURFACES

| Item | Blocks | Blocked by |
|---|---|---|
| Consolidated reporting home | nothing | the joins |
| Competency gap report | nothing | `jobrole_competency_map` + `skill_matrix_item` |
| Development plan report | nothing | M-02 |
| Certification expiry report | nothing | `certification_type` |
| **O-01** Skill Deficit KPI honesty fix | nothing | nothing — **fully parallelisable** |
| 9-box second axis | nothing | TL-02 |

> **Building tier 4 before tier 1 produces empty reports.** That is why the
> reporting home — already approved — sequences last, not first.

## Items that block nothing and are blocked by nothing

**These can be parallelised, or dropped under pressure without consequence:**

| Item | |
|---|---|
| **O-01** Skill Deficit KPI honesty fix | XS. Currently reports ~97% from an empty-array test |
| **M-01** learning edit controls | XS. The endpoint already accepts both fields |
| **L-21 / L-22 / L-23** display bindings | **re-costed to S** — the record does not reach those screens |
| **C-10** library drawer fields | **display tier confirmed** — the data is already on the wire and discarded. **Best value in the set** |
| **L-03R** dead panel deletion | ✅ **already done** (D-001) |

---



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
| S-01 | `talent_interviewpanelController` tenant fix | G-SEC-11 | 7 | **XS-S** · `talent_interviewpanelController.php` | nothing | — | AT-S01 | API | ✅ **API-verified** (`15791bca`) |
| S-02 | G-SEC-12 actor identity | G-SEC-12 | 2, 9 | **M** — **76 sites / 16 files** (est. was 33; low by 2.3×) | ~~event store~~ **UNBLOCKED** | — | AT-S02 | API | ✅ **API-verified** (`d70a204c`) |
| S-03 | Remaining leaks, data-class order | G-SEC-11 | all | **ESTIMATE PENDING** | customer readiness | — | AT-S03 | API | Not started |
| S-04 | 37 guard candidates hand-verified | G-SEC-11 | — | **S** · from `c23-result-FULL-912.json`, **no re-run** | S-03 scope | — | — | DB | Not started |
| S-05 | C37 ten checks (1 done) | — | — | **S** | C34 calibration | — | — | API | 1 of 10 |
| S-06 | C23 write-half phase | — | — | **ESTIMATE PENDING** | C24 gate | tenant + row register | — | API | Not started |
| S-07 | C23 regression guard in CI | G-QUAL-02 | — | **S** · guard exists; needs a CI hook | prevents regrowth | — | — | API | Not started |
| S-08 | G-SEC-01 authorization coverage | G-SEC-01 | all | **ESTIMATE PENDING** — superseded counts | — | rights matrix | — | API | Not started |

## Tier 1 — Structural foundations

| ID | Title | Gap | Thread | Cost (R7) | Blocks | Blocked by | Test | Verif | Status |
|---|---|---|---|---|---|---|---|---|---|
| F-01 | **The five join tables, ONE migration** | G-DATA-06, G-FLOW-26 | 1-8 | **L** · new migration + `02-domain-model.md` §2.1 DDL | **everything in T3** | — | AT-F01 | DB | ✅ **APPLIED** (`7df8c1c7`) |
| F-02 | `certification_type` + `certification_competency_map` | G-CERT-01 | 3, 8 | **M** · §10.1 DDL, steps 3b/9b | L-09, thread 8 | F-01 | AT-F02 | DB | ✅ **APPLIED** (`7df8c1c7`) |
| F-03 | **Two** restored tables *(not three — see D-007)* | Q-B5 | 2, 3, 8 | **S** · §4.2 idempotent DDL | evidence projector | — | AT-F03 | DB | ✅ **APPLIED** (`7df8c1c7`) |
| F-04 | `skill_matrix_item` + `sub_institute_id` | G-DATA-08 | 1, 5, 6 | **M** · step 12 normalisation | guards seeing tenancy | F-01 | AT-F04 | DB | ✅ **APPLIED** (`7df8c1c7`) |
| F-05 | `reporting_manager_id` + `head_user_id` + cycle validation | Q-B1 | 2, 4, 9 | **M** · `tbluser`, `hrms_departments` migration | every approval flow | — | AT-F05 | DB | ✅ **APPLIED** (`f293edb0`) |
| **F-05a** | **Call `ReportingLineValidator::canAssign()` from EVERY write path that sets `reporting_manager_id`** | Q-B1 | 2, 4, 9 | **S** · employee create/edit · onboarding · bulk import · admin screens | the guarantee itself | F-05 | AT-F05a | API | **NOT STARTED — the guarantee is theoretical until this lands** |
| **F-05b** | **Manager assignment mechanism** — bulk and individual, for `reporting_manager_id` **and** `head_user_id` | Q-B1 | 2, 4, 9 | **M** | Slice 2's demo | F-05, F-05a | AT-F05b | SCREEN | **NOT STARTED** |
| F-06 | Tri-state rights columns | G-SEC-06 | 9 | **S** · both rights tables | rights population | — | AT-F06 | DB | Not started |
| F-07a | Text→FK **columns added**, nullable, unread | G-DATA-06 | all | **M** | F-07b | F-01 | AT-F07 | DB | ✅ **APPLIED** (`7df8c1c7`) |
| F-07b | Text→FK **backfill + unmatched report + drops** | G-DATA-06 | all | **L** · R8 on the drops | joins by key | F-07a | AT-F07b | DB | Not started |
| F-08 | `portal_identity` | Q-D4 | 7 | **M** · §7.3 DDL | candidate conversion | — | AT-F08 | DB | Not started |
| F-09 | `library_map_skill` join table | G-DATA-07 | 1 | **S** · 3,270 rows to split | — | F-01 | AT-F09 | DB | ✅ **APPLIED** (`7df8c1c7`) |

## Tier 2 — Mechanisms

| ID | Title | Gap | Thread | Cost (R7) | Blocks | Blocked by | Test | Verif | Status |
|---|---|---|---|---|---|---|---|---|---|
| X-01 | **Rights matrix populated** — targets **`tblgroupwise_rights_g2g`** (the Next.js sidebar). **THE MOST VISIBLE CHANGE IN THE PLAN** | ~~G-SEC-07~~ (corrected) | 9 | **L** · seeder from §3.1-3.7 | X-02, thread 9 | F-06 ✅, roles ✅, screen→menu map | AT-X01 | **SCREEN** | Blocked on the mapping |
| ~~X-01c~~ | ~~rights-table consolidation~~ | — | — | — | — | — | — | — | ❌ **CANCELLED** — two products, two tables, each keeps its own (G-SCOPE-01) |
| X-02 | Route permission declarations | G-SEC-04 | all | **ESTIMATE PENDING** | authorization | X-01 | — | API | Not started |
| X-03 | **C19 picker mechanism** | G-LIB-08 | 1 | **M-L** · `LibraryController` meta, `library-form.tsx`, `library-config.ts`, `services/competency/libraries.ts` | L-01/02/04 | §10.0 *(decided)* | AT-X03 | SCREEN | Not started |
| X-04 | **Event store + projector/reactor split** | G-STR-04 | 2, 9 | **L** · `05-data-flow-contracts.md` §1 DDL | X-05, threads 2/9 | ~~S-02~~ **now unblocked** | AT-X04 | DB | Not started |
| X-05 | `task_status_history` | G-STR-04 | 2 | **M** | thread 2 | X-04 | AT-X05 | DB | Not started |
| X-06 | Notification service + terminology | Q-F1 | 4, 9 | **M** · §8 DDL | readiness gates | — | AT-X06 | API | Not started |
| X-07 | Readiness gates + asymmetric switching | M1 | 6, 8 | **M** · §8 | honest surfaces | X-04 | AT-X07 | DB | Not started |
| X-08 | **Seed-library import flow** | G-FLOW-03, Q-C1 | 1, 6 | **L** · §9 | coverage | F-01 | AT-X08 | SCREEN | Not started |

## Tier 3 — Connections

### C-T3-ONT · Wire the ontology graph to real adjacency · **M**

**Re-filed from F-6's replacement.** No longer a rebuild of a deleted screen —
an **enhancement of a screen we have decided to keep** (D-012).

**Today** the graph is a third-party reference view: adjacency is not computed
from our data, and only `sub_institute_id` is sent to an external host.

**Target:** adjacency computed from **`jobrole_competency_map`** — one of the five
join tables created in the Phase 3 foundation migration (D-007) — so the graph
shows *this organisation's* role/competency structure.

**Blocked on:** `jobrole_competency_map` being populated. That is **F-07b's
backfill**, already queued.

**Cost — M.** Endpoint returning nodes/edges from the join table, a swap of the
iframe for an in-app graph, and the existing screen's loading/error states reused.
**The external host and the tenant-id-in-URL question both disappear with it**,
which is the second reason to do it.

**Until then the interim label (D-012, Check B) is what prevents the screen from
being read as organisational fact.**


| ID | Title | Gap | Thread | Cost (R7) | Blocks | Blocked by | Test | Verif | Status |
|---|---|---|---|---|---|---|---|---|---|
| L-01 | `department_id` from the Job Role form | G-LIB-01 | 1 | **XS** *(after X-03)* · `library-config.ts` | — | X-03 | AT-L01 | SCREEN | Not started |
| L-02 | `department_id` from the Skill form | G-LIB-01 | 1 | **XS** *(after X-03)* | — | X-03 | AT-L02 | SCREEN | Not started |
| ~~L-03~~ | ~~Reach the detail panel~~ | G-LIB-06 | — | — | — | — | — | — | **SUPERSEDED by L-03R** |
| L-03R | Delete the dead panel | G-LIB-06 | — | **XS** | — | — | AT-L03R | SCREEN | ✅ **BUILT** (D-001) |
| L-04 | Job Level → `s_level_responsibility` | G-LIB-07 | 1 | **XS** *(after X-03)* | — | X-03 | AT-L04 | SCREEN | Not started |
| L-05 | Honour Status at assignment time | G-LIB-05 | 3 | **S** · `RoleMappingController`, `StudioController` | — | F-01 | AT-L05 | API | Not started |
| L-06 | Delete impact count / block | G-LIB-02 | — | **S** · `library-tab.tsx` | — | — | AT-L06 | SCREEN | Not started |
| L-07 | Job Titles → `s_user_skill_jobrole` | G-LIB-02 | 1 | **M** | — | F-07 | AT-L07 | DB | Not started |
| L-08 | Learning Resources → course refs | G-FLOW-26 | 3, 4 | **M** | thread 4 | F-01 | AT-L08 | SCREEN | Not started |
| L-09 | Certifications → `certification_type` | G-CERT-01 | 3, 8 | **M** | thread 8 | F-02 | AT-L09 | SCREEN | Not started |
| L-10 | Import on every library tab | G-LIB-04 | 1 | **M** | — | X-08 | AT-L10 | SCREEN | Not started |
| L-11 | Join on ids, not titles | G-DATA-06 | all | **L** | — | *(= F-07)* | AT-F07 | DB | Not started |
| L-12 | One shared category table + applicability | G-LIB-02 | 1 | **L** | L-13, L-20 | F-01 | AT-L12 | DB | Not started |
| L-13 | Propagate taxonomy renames | G-LIB-02 | 1 | **M** | — | L-12 | AT-L13 | DB | Not started |
| L-14 | Task catalogue → competency | G-LIB-03 | 2 | **L** | thread 2 | *(= F-01)* | AT-F01 | DB | Not started |
| L-15 | Compliance Relevance → boolean + regulation ref | G-LIB-02 | 8 | **M** · migration + `library-config.ts` | thread 8 | F-01 | AT-L15 | SCREEN | Not started |
| L-16 | Risk Implications → severity enum → `competency.criticality` | G-LIB-02 | — | **M** | — | F-01 | AT-L16 | DB | Not started |
| L-17 | `assessment_method` enum, **additive** | G-LIB-02 | 3 | **M** · keeps both element columns | — | F-01 | AT-L17 | DB | Not started |
| L-18 | Importance → `competency_kasba_item.weight` | G-LIB-02 | 1 | **S** | — | F-01 | AT-L18 | DB | Not started |
| L-19 | Experience → numeric min years, text kept | G-LIB-02 | 6, 7 | **M** · parse clear patterns, **report coverage** | thread 6 | F-01 | AT-L19 | DB | Not started |
| L-20 | Three `*_tags` → shared categories | G-LIB-02 | — | **S** | — | L-12 | AT-L20 | DB | Not started |
| L-21 | Performance Metrics on the rating screen | G-LIB-02 | 3 | **S** *(re-costed from display)* | — | F-01 | AT-L21 | SCREEN | Not started |
| L-22 | Measurement Metrics as scale anchor | G-LIB-02 | 3 | **S** *(re-costed)* | — | F-01 | AT-L22 | SCREEN | Not started |
| L-23 | Development Methods at plan creation | G-LIB-02 | 4 | **S** *(re-costed)* | — | F-01 | AT-L23 | SCREEN | Not started |
| C-10 | Library drawer: 5 unrendered fields | — | — | **display** · data already on the wire | — | — | AT-C10 | SCREEN | Not started |
| M-01 | Learning edit controls | G-FLOW-26 | 4 | **XS** · endpoint already accepts both | — | — | AT-M01 | SCREEN | Not started |
| M-02 | Learning assignment records its gap | G-FLOW-26 | 4 | **M** | thread 4 | F-01 | AT-M02 | SCREEN | Not started |
| M-03 | **Role-mapping create path** + reinstate the button | **G-MAP-01** | 1 | **S-M** · surface `SchoolSetupController.php:392-408` | thread 1 | F-01 | AT-M03 | SCREEN | Not started |
| M-04 | `skill_matrix_item` tenant column | G-DATA-08 | 1, 5 | **XS** *(inside F-04)* | — | *(= F-04)* | AT-F04 | DB | Not started |
| O-01 | Skill Deficit KPI honesty | G-DATA-05 | — | **XS** · `employee-directory.tsx` | — | — | AT-O01 | SCREEN | Not started |
| O-02 | Directory ratings via one service | — | 1 | **S** | — | F-04 | AT-O02 | API | Not started |
| O-03 | `ExcelAutomationAgentController@credentialStatus` | G-SEC-11 | — | **XS-S** | — | — | AT-O03 | API | Not started |
| O-04 | Three report-route leaks | G-SEC-11 | — | **S** | — | — | AT-O04 | API | Not started |
| O-05 | Read `HrmsController` (31 routes) | C21 | — | **S** *(reading)* | S-03 scope | — | — | — | Not started |
| LM-01 | Retire `contentLibraryControllerOld` | — | — | **S** · ⚠️ **R8 + approval** | — | — | AT-LM01 | API | Not started |
| LM-02 | Course Builder prompt enrichment | — | 4 | **XS** · `course-builder-panel.tsx` | — | — | AT-LM02 | SCREEN | Not started |
| LM-03 | Surface the LMS funnel | G-FLOW-05 | 4 | **S** | — | — | AT-LM03 | SCREEN | Not started |
| T-01 | One write path for `task.status` | S-6 | 2 | **M** · 10 writing files | — | S-6 verification | AT-T01 | API | Not started |
| T-02 | Surface `delay_category` | G-FLOW-24 | 2 | **XS** · mechanism already correct | — | — | AT-T02 | SCREEN | Not started |
| TL-01 | *(= S-01)* interview panel leak | G-SEC-11 | 7 | **XS-S** | — | — | AT-S01 | API | ✅ **API-verified** (`15791bca`) |
| TL-02 | Performance goal → `competency_id` | **G-FLOW-26** | 5 | **M** · `PerformanceGoalController.php` | 9-box | F-01 | AT-TL02 | DB | Not started |
| TL-03 | Requisitions read `jobrole_competency_map` | **G-FLOW-26** | 7 | **M** | thread 7 | F-01 | AT-TL03 | SCREEN | Not started |
| TL-04 | Resolve the two `OnboardingTaskController`s | — | — | **S** · read both first (R6) | — | — | AT-TL04 | API | Not started |

## Tier 4 — Surfaces

| ID | Title | Gap | Thread | Cost (R7) | Blocks | Blocked by | Test | Verif | Status |
|---|---|---|---|---|---|---|---|---|---|
| R-01 | Consolidated reporting home | Q-A4 | — | **ESTIMATE PENDING** | — | F-01, F-02 | AT-R01 | SCREEN | Not started |
| R-02 | Competency gap report | — | 1 | **M** | — | R-01 | AT-R02 | SCREEN | Not started |
| R-03 | Development plan report | — | 4 | **M** | — | R-01, M-02 | AT-R03 | SCREEN | Not started |
| R-04 | Certification expiry report | G-CERT-01 | 8 | **M** | — | R-01, F-02 | AT-R04 | SCREEN | Not started |
| R-05 | 9-box second axis | **G-FLOW-26** | 5 | **M** | — | TL-02 | AT-R05 | SCREEN | Not started |

### §5.1 — whole-plan reconciliation

**Run across the plan, not per module.** Items appearing in several write-ups
appear **once** here.

| | Count |
|---|---:|
| Items deduplicated into one row | **4** — L-11 = F-07 · L-14 = F-01 · M-04 = F-04 · TL-01 = S-01 |
| ID collisions fixed | **3** — LMS `L-01/02/03` → `LM-01/02/03` |
| Superseded | **1** — L-03 by L-03R |
| **ESTIMATE PENDING** | **5** — S-03, S-06, S-08, X-02, R-01 *(S-02 derived: 76 sites / 16 files)* |
| Already built | **1** — L-03R |

> **The headline result of Gate C, stated as one:** across six modules, §5.1
> returned **2 new / 3-5 already approved** every time. **Gate C found almost
> nothing that Gate B's domain model had not already anticipated.** That is what a
> correct model looks like, and it is the strongest evidence that
> blueprint-before-code was the right sequencing.

---

# §6. SLICES

**Phase 3 must not be one long invisible build.** Five slices, each ending in
something demonstrable.

---

## SLICE 1 — "One job role, one employee, one visible gap"

**The shortest path to a visible capability chain.** Triz's assumption was seed
import + join tables + one role's mapping + a gap on a profile. **That is right,
with one correction: the seed import is not needed for Slice 1** — the mapping
rows already exist (79,295 of them). Importing is how you get *coverage*; Slice 1
only needs *one role*.

### Items, in order

| # | Item | Why it is in Slice 1 |
|---|---|---|
| 1 | **S-01** interview panel leak | Tier 0. Nothing demos until security items in flight are closed |
| 2 | **F-01** the five join tables, one migration | The chain has no shape without them |
| 3 | **F-04** `skill_matrix_item` + tenant column | The measured side of the gap |
| 4 | **F-07** text→FK, scoped to **one job role** | Full backfill is not required to demo one role |
| 5 | **M-03** role-mapping create path | So the mapping is *authorable*, not just seeded |
| 6 | A gap read: required vs measured, on the employee profile | The visible output |

**Deliberately NOT in Slice 1:** the rights matrix, the event store, the picker
mechanism, the import flow, every report. **None is needed to show the chain.**

### Demo script — "Staff Nurse"

1. Open **Competency → Library & Taxonomy → Job Role**, show *Staff Nurse* exists.
2. Open **Framework & Role Mapping**, add three competencies at required proficiency — **using M-03's create path, not cell-by-cell**.
3. Open **Organization → Employee Directory**, pick an employee whose role is *Staff Nurse*.
4. Rate them on one of the three competencies.
5. Open their **capability profile**: **required 3, measured 1, gap 2** — resolved **by key**.
6. Rename the job role to *Registered Nurse*. **The mapping survives.** *(Today it silently detaches — this is the moment that shows what was fixed.)*

### What a user can do at the end
Define what a role requires, measure a person against it, and **see the gap** —
for one role, end to end.

### What is still missing
Coverage (one role, not all), learning assignment, evidence from tasks,
reports, and the rights matrix — so **everyone still sees everything**.

### How far away
**Honestly: F-01 + F-04 + F-07 are the largest single migration in the plan
(two L's and an M), and F-07 is a backfill against 283,126 string-joined rows even
scoped to one role.** Slice 1 is **not a quick win** — it is the *shortest* path,
which is not the same thing. Everything after it is faster, because the foundation
is laid once.

---

## SLICE 2 — "Roles mean something"

**Items:** F-06 tri-state rights → **X-01 rights matrix populated with the
before/after menu diff** → X-02 route permission declarations → S-08.

**Demo:** log in as Employee, then as HR, then as Admin — **three different
products**. Today all three see essentially the same 1,500-1,657 menus, with
Employee seeing *more* than Admin.

**Still missing:** the whole capability chain — this slice makes roles mean
something, not capability. **Gate:** X-01 needs the before/after menu diff reviewed
by Triz before rollout.

**Why it now runs first:** independent of every join table, visible in one deploy,
and it closes `G-SEC-07` — today the rights matrix is uniform, so **Employee sees
1,657 menus against Admin's 1,500**. That inversion is demonstrable on a screen
without a single migration.

---

## SLICE 3 — "Work and learning feed capability"

**Items:** S-02 (G-SEC-12) → X-04 event store → X-05 `task_status_history` →
F-03 restored tables → `competency_evidence` projector → M-02, M-01.

**Demo:** complete a job-role task → evidence appears against the competency it
exercises → a gap triggers a learning assignment **recorded against that gap** →
completion raises proficiency where policy allows.

**This is the loop the product is sold on, closed for the first time.**

**Still missing:** recruitment, performance, reports.
**Note:** S-02 first is not optional — an event store on untrustworthy `actor_id`
inherits a corrupted audit trail on day one.

---

## SLICE 4 — "The rest of the chain"

**Items:** F-02 + L-09 certifications → F-05 reporting line → TL-02 performance →
TL-03 recruitment → F-08 `portal_identity` → X-03 picker → L-01/02/04.

**Demo:** a requisition generating its scorecard from the framework; a review whose
goal points at a real competency; a certificate resolving to a known type.

**Still missing:** reports, and the candidate portal *(deferred by design)*.

---

## SLICE 5 — "See it"

**Items:** X-08 import → R-01 reporting home → R-02/03/04 → R-05 9-box → O-01.

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

> ## ⛔ C24 — RELEASE PRECONDITION
> **No customer tenant is created on this platform until the tenant-isolation test
> suite passes end to end.** A business rule, not an engineering task. The gate is
> a **passing suite**, not a completed fix — because C22 showed a fix can look done
> and not be.

---

# §8. CONTINGENT ITEMS

**A plan that hides its own uncertainty is worse than one that shows it.**

| Item | Depends on | If it comes back differently |
|---|---|---|
| **S-03** remaining leaks | **S-04**'s classification of 37 candidates | If a **fourth defect class** appears (not wrong-scope, not no-scope, not actor), Tier 0 grows and Slice 1 slips |
| **C34's 114** | **S-05** — C37's ten checks | **One real hit** → C34 calibrates and 114 become a worklist. **Ten false positives** → C34 closes as a **proven negative** and the no-scoping class does not exist here. Either ends it |
| **S-02** G-SEC-12 | its own hand-classification | If most of the 33 are SUBJECT, this is small. If most are IDENTITY, it is **M-L** and the event store slips |
| **X-03** picker | actual cost of the meta pipeline | If it exceeds M-L, L-01/02/04 stay XS but arrive later |
| **X-08** import | whether `SchoolSetupController`'s bulk path generalises | If it does not, the import is **L** and Slice 5 slips |
| **F-07** text→FK | how many rows fail to match on backfill | **Unmatched rows are reported, never guessed** (§10.0). A high unmatched rate means manual reconciliation |
| **L-17** | whether the two element columns are a controlled vocabulary | Already measured: **10 terms cover 78%** — it is a library. Additive, not a substitution |
| **F-02** certifications | whether §10 gained a step | **Confirmed a genuine Gate B omission**; steps 3b/9b added |

---

# §9. NOT IN PHASE 3

**This section exists so nobody later mistakes a decision for an oversight.**

| Item | Reason | Ref |
|---|---|---|
| **CRM** (Marketing, Leads, Master Fields) | Deferred. Code and data intact, hidden from nav, no Phase 3 work | Q-A4 |
| **The Blade UI** — 173 views, 21 controllers, its own menu tree (200 rows, 30 not in `_g2g`) and rights table (1,254 rows) | **OUT OF SCOPE — decided 2026-08-07.** Not the product being built; not "deferred with intent to retire". Live and maintained (last touched 2026-07-31). Its **routes** are covered by the C23 tenant guard; its **screens, menus and rights** were never audited | **G-SCOPE-01** |
| **Applicant-facing candidate portal** | Phase 3 defines the identity model, isolation boundary and conversion step. **Building the portal is a separate deliverable** | Q-D4 |
| **External trainer / vendor identities** | Same pattern as Candidate, deferred. The model must generalise (`portal_identity` + type discriminator) but they are not designed now | Q-D4 |
| **Delegation / acting manager** | Not Phase 3 build work. Two rules designed in now: audit records both parties; delegation never widens scope | A4 |
| **Leave convergence steps 3-4** | Fold local leave flags into the shared rights matrix, then drop `hrms_leave_role_permissions`. First post-Phase-3 items | A7 |
| **27 deferred nav rows** | Itemised in `01b-scope-triage.md` | Q-A3 |
| **Compensation** | Not in the golden threads | — |
| **Template management** | Not in the golden threads | — |
| **65 nav rows marked DELETE** | Approved in principle; **no row removed without a reversible script + backup** | Q-A3 amendment 4 |
| **The 9-box surface** *(if T4 defers)* | Thread 5's data lands in Phase 3; the grid itself may be Phase 4 | §2 |

---

# §10. DEFINITION OF DONE

**Phase 3 is complete when all four hold:**

1. **All Tier 0 and Tier 1 items shipped and verified.**
2. **Every golden thread either works end to end or is explicitly deferred with a reason recorded.**
3. **The C23 tenant-isolation suite is green, including the write half.**
4. **One customer-ready demonstration of the capability chain exists** — Slice 1's demo script, run for real.

**Anything beyond that is Phase 4.**

**Adopted as proposed.** The evidence does not contradict it, and two points
support it: Tier 2's mechanisms are *enabling*, not *demonstrable* — tying "done"
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
| 6 | **The rights matrix diff removes screens people rely on** | Rollout blocked | Triz's per-role review finds losses — **which is why the review exists** |
| 7 | **Process regrowth** | The failure mode of the last ten turns | New rules or numbered checks appear without one being retired |

---

**END OF PLAN. Awaiting approval. Nothing is built from this until approved.**

---

## AMENDMENT 2026-08-10 — THE MACHINERY THE PLAN CARRIED THREADS FOR

**This is not scope creep. It is an UNDERSTATED PLAN.**

All of the consumers below were specified in `05-data-flow-contracts.md` §2.1 and
**never absorbed here**. Every one serves a thread this plan already commits to
completing: **the plan carried the THREADS and missed the MACHINERY.**

Found by checking the catalogue's 9 reactors against this document during item 6,
slice 3 - **3 were carried, 6 were not.**

### Already carried, under different names — no new work

| Reactor | Carried as |
|---|---|
| `NotificationDispatcher` | **X-06** — Notification service + terminology (Q-F1), **M** |
| `AccessRevoker` | line 336 — offboarding reactor, **T3** |
| `TaskReassigner` | same line 336 item — the plan treats both as one deliverable |

### DECISION — the three assigners collapse to TWO, not one

Decided from the write path and record shape, not preference:

- **`MandatoryLearningAssigner` + `LearningAssigner` COLLAPSE.** Both assign
  courses into **`lms_assignments`** (`LearningAssignmentController:31`), same
  record shape, differing only in trigger — role-mandatory vs plan-approved.
  **One service, two entry points.**
- **`RemediationRecommender` DOES NOT collapse.** §2.1:232 has it *"find the
  course via the competency-derived path and SHOW IT immediately"* — **a
  recommendation, not a write.** Its second use (:244, renewal assignment) writes,
  but its distinguishing work is *finding* the course. **Different
  responsibility, kept separate.**

**So five new items, not six or four.** Reported as measured rather than rounded
to the tidier number.

### NEW ITEMS

| # | Item | Thread | Tier | Cost (R7) | Files |
|---|---|---|:-:|:-:|---|
| X-11 | **`CertificateIssuer`** — auto-issue on `course.completed`. Closes **G-FLOW-05's manual-claim gap**; the plan carried certificate *upload/resolve* only | 3 | T2 | **M** | `app/Services/Events/CertificateIssuer.php`, certification tables |
| X-12 | **`LearningAssigner`** (absorbing `MandatoryLearningAssigner`) — two entry points | 2, 3 | T2 | **M** | `app/Services/Events/LearningAssigner.php`, `lms_assignments` |
| X-13 | **`RemediationRecommender`** — competency-derived course lookup (S4), shown immediately per Q-B3 | 3, 8 | T3 | **M** | `app/Services/Events/RemediationRecommender.php` |
| X-14 | **`OnboardingLauncher`** — creates the journey on `employee.hired` | 1 | T2 | **M** | `app/Services/Events/OnboardingLauncher.php`, `talent_onboarding_journeys` |
| X-15 | **`FeatureGateApplier`** — applies a gate on `readiness_gate.changed`; **ON automatic, OFF never** (§4) | M1 | T3 | **S** | `app/Services/Events/FeatureGateApplier.php` |

**Plan item count moves: ~40 → ~45.** The figure quoted before this amendment
was understated by these five.

### THE REVERSE CHECK — run, and its LIMIT stated

*Does this plan imply an event the catalogue lacks?* **Nothing found — and the
reason matters more than the result.**

**This document names no events at all.** The only dotted tokens in it are column
references (`task.skill_id`, `task.status`). The plan speaks in **capabilities**;
the contracts speak in **events**.

> **So the reverse check cannot find a missing event by name, and returning
> nothing is NOT evidence that the catalogue is complete.** The reconciliation is
> one-directional because the two documents use different vocabularies. Recorded
> so the empty result is never read as a clean bill.

---
