# 08 — Connection Plan

**The deliverable the Phase 3 brief asked for.** Assembled from the CONNECTIONS TO
BUILD sections of the six module write-ups. Nothing is re-derived here.

**Status:** `DRAFT — §1–§3 complete. §4–§11 in assembly.`

⚠️ **Headline numbers corrected by `12-gate-c-verification.md` V6** — 283,127 (not 283,126), 2.7% (not 3.0%), 29 controllers (not 30). The write-route coverage claim is **withdrawn** pending re-derivation.
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

> **283,127 rows** carry the product's core relationships — *which job role needs
> which skill*, *which tasks belong to which role* — and **every one of them is
> resolved by matching a piece of text**, not by a proper database link.

*What this means in practice:* if someone renames a job role from "Staff Nurse" to
"Registered Nurse", every task and every skill attached to it **silently stops
matching**. Nothing errors. Nothing warns. The data is simply orphaned.

**Caveats, which travel with the number:**
- **Not 283,127 defects.** It is 283,127 rows across four tables, each resolving its relationship by string. *(Of these, 283,126 carry a populated key; one row's is empty. Quote the row count.)*
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

## The three supporting numbers

| Number | What it is | Caveats that must travel with it |
|---|---|---|
| **283,127** | rows whose relationships resolve by string, across four tables verified individually | not defects; not a column count; test data; the structure is the finding |
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

*§4–§11 follow in the next assembly pass.*
