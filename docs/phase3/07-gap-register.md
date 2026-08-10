# 07 — Gap register

Every gap found in Phase 3, with a stable ID, severity, owning module and the work
it implies. Started early (normally a Gate C artefact) at Triz's request.

**Read `G-DATA-06` first.** It is the finding that explains the others: the
product's load-bearing relationships are joined by name, not by key. The security
findings that follow are breaches to be closed; **G-DATA-06 is why the modules do
not connect in the first place.**

Severity: **S1** blocks a sale or breaks data integrity · **S2** breaks a core
workflow · **S3** degrades the product · **S4** cosmetic.

| Status | Meaning |
|---|---|
| `OPEN` | Confirmed, not addressed |
| `PARTIAL` | Partly addressed in an earlier phase |
| `DESIGNED` | Fix specified in a Gate B/D document, not built |

---

# ⭐ G-DATA-06 — THE HEADLINE FINDING · **S1**

> ## 283,126 relationship rows are held together by string matching, not keys.

**This is promoted above the security findings deliberately.** The security gaps
are breaches to be closed; **this one explains why the product does not function as
a connected system.** Until now L-11 was an argument. It is now a measurement.

### ⭐ READ WITH `G-FLOW-26` — THEY ARE A MATCHED PAIR AND TOGETHER THEY ARE THE DIAGNOSIS

| | |
|---|---|
| **G-DATA-06 — the SUPPLY side** | the relationships that **do** exist are joined by **strings**, not keys |
| **G-FLOW-26 — the DEMAND side** | three relationships the product is **sold on** do not exist at all — they are **named and not built** |

**The single concrete illustration: the 9-box grid has performance on one axis
and nothing to put on the other.** Performance has never been able to read a
capability measurement, because the join was never built — only the word
*competency* appears, as a dropdown label and a validator enum value.

Neither finding alone explains the product's state. Together they do.

## Exactly what the number counts

**283,126 = the sum of the ROW COUNTS of four tables**, each verified individually
against the live schema:

| Table | Rows | Text columns naming another entity, with no `*_id` |
|---|---:|---|
| `s_user_jobrole_task` | **85,662** | `jobrole` |
| `s_user_skill_jobrole` | **79,295** | `skill`, `jobrole`, `skill_code` |
| `s_jobrole_skills` | **62,208** | `skill`, `jobrole`, `skill_code` |
| `s_jobrole_task` | **55,961** | `jobrole` |
| **Total rows** | **283,126** | |

### What it INCLUDES
Rows in the four tables that carry the product's load-bearing relationships —
**which job role needs which skill**, and **which tasks belong to which job role**.
Every one of those relationships is resolved by matching a **name string**.

### What it EXCLUDES — state this whenever the figure is used
- **It is not 283,126 defects.** It is 283,126 rows in four tables, every one of which resolves its relationship by string.
- **It is not a count of columns.** That is the separate figure of 32 (below), and the two must never be conflated.
- **It does not include** the other 28 cross-table text references, which are much smaller.
- **It does not mean the data is wrong today.** It means **any rename silently detaches it**, and nothing can join to it by key.
- **Provenance:** test data (R2). The *structure* is the finding, not the volume — a customer's own library would have the same shape.

## Why this is the precondition, not one connection among twenty-three

A job role cannot be told what capability it requires; an employee's measured skill
cannot be tied to the role that needs it; a task cannot report the competency it
exercised. **Every golden thread crosses at least one of these four tables.**
`L-11` is therefore **not a connection to be prioritised against others — it is the
precondition for the rest of them existing.**

## C30 — the three lists · only (a) is quotable

The sweep found **49** populated text columns naming an owned entity with no
matching `*_id`. That 49 **must not be quoted whole.**

| List | Count | Quotable? |
|---|---:|---|
| **(a) REFERENCES — cross-table AND self-reference. The finding** | **36** | ✅ **Yes** |
| (b) Own identity — `hrms_departments.department`, `s_user_jobrole.jobrole`, `s_users_skills.skill_code`. The name legitimately lives here | 5 | ❌ Not a defect |
| (c) Attribute noise — `skill_status`, `skill_importance`, `jobrole_category`, `skill_type` | 8 | ❌ Noise |

**The rule was corrected, not hand-patched.** The first version tested only *"is
this the owner table"*, which wrongly classified a self-reference — a column on
`s_user_jobrole` pointing at a **different** job role is still a reference. The
corrected rule requires **both** that the table owns the entity **and** that the
column denotes *this row's own* identity; a `related_`/`sub_`/`_ids` qualifier
fails the second test. Re-running moved the quotable count **32 → 36** and pushed
six owner-table attributes into noise.

**The 283,126 figure is independent of this classification.** Those four tables
were verified individually, table by table; the 36 comes from a schema-wide rule.
**They are two separate measurements and must never be presented as one** — which
is precisely why correcting the rule from 32 to 36 left 283,126 untouched. State
that independence whenever both figures appear near each other.

---

# G-DATA-08 — `s_skill_matrix` has no tenant column · **CANDIDATE, not confirmed**

The per-employee **capability measurement store** (169 rows) has **no
`sub_institute_id`**. It is tenant-scoped **only indirectly**, via
`user_id` → `tbluser.sub_institute_id`.

**Defensible if every query joins `tbluser`. Silently global if any query counts
or aggregates the matrix directly.**

Surfaced while hand-verifying a C34 candidate: `CompetencyDashboardController@index`
returns an **identical body to both tenants despite filtering on `sub_institute_id`**
at lines 27, 39 and 46. That combination is best explained by a panel reading the
matrix without the join — but **that is a hypothesis, not a verdict**, and
confirming it means reading the panel queries.

**The decision it forces now:** §10 step 12 rebuilds this table as
`skill_matrix_item`. **Whether that carries a tenant column must be decided
deliberately, not inherited.**

---

## G-CERT-01 addendum — `verified_by` was caller-supplied too · ✅ **BOTH HALVES CLOSED**

**Recorded here, not only under G-SEC-12, because a reader looking at
certifications must find it.**

`CertificationController` had **two** trust defects on the same record, and they
compound:

| Half | Defect | Closed by |
|---|---|---|
| **1** | `verification_status` taken from the request — **a credential could declare itself verified** | **D-002** (`2026-08-06`) |
| **2** | `verified_by` taken from the request — **a caller could also name WHO verified it** | **D-006** (`d70a204c`) |

**Together they meant a credential could assert both that it was verified and who
signed it off.** Neither claim was checkable, and nothing looked wrong.

> **Certification trustworthiness is what a regulated customer tests first.** A
> credential that verifies itself, signed by a person who never saw it, is the
> exact artefact an auditor asks to see the provenance of.

**Both halves are now closed.** `verification_status` is server-set to `pending` on
create; `verified_by` resolves from the token via `g2gActorId()`. The legitimate
verify path still works and still stamps `verified_by` / `verified_at` — but from
identity, not from input.

⚠️ **Still open:** that verify path is **not role-gated** (G-SEC-01). Anyone who can
reach it can verify. Logged, not fixed here.

---

# G-DATA-07 — `s_library_map.skill_ids` packs ids into a TEXT column · **S2** (C31)

**Worse than the string-join problem, and it deserves its own line.**

`s_library_map.skill_ids` holds **multiple skill ids packed into one TEXT column**,
populated on **3,270 rows**.

| | String join (G-DATA-06) | **Packed ids (this)** |
|---|---|---|
| Can be joined at all? | yes, on a name | **NO** — not one-to-one, not one-to-many, not any way |
| Requires `LIKE '%,3,%'` to query? | no | **yes** — and it matches `13`, `23` and `130`. **This is a WRONG-RESULTS bug, not a speed problem**: skill 3 appears to be mapped wherever skill 13 or 130 is. "Unindexable" understates it |
| Breaks on a delimiter in the value? | n/a | **yes, silently** |

A string join is a *bad* key. A packed list is **not a key at all** — every read
becomes a substring scan that is both slow and wrong at the boundaries.

**Fix:** a proper join table `library_map_skill (library_map_id, skill_id)`, backfilled
by splitting the column, with unparseable rows reported rather than guessed (§10.0
migration path). **Cost: S** — 3,270 rows, one new table, one backfill, and the
readers are few.

**Not folded into G-DATA-06** because the remedy is different: G-DATA-06 needs a
key column added beside a name; this needs a *table* that does not exist.

---

## G-FLOW-26 — a vocabulary of connection without the connection · **S2**

**Three modules now show the same shape: the word "competency" is present, the join
is not.**

| Thread | Where | What exists | What does not |
|---|---|---|---|
| **3** · Competency ↔ LMS | `library-config.ts:172` | a *Learning Resources* **text field** | any course reference (`L-08`) |
| **5** · Competency ↔ Performance | `PerformanceGoalController.php:93,167`; `PerformanceOverviewController.php:314` | `'competency'` as a **validator enum value** and a **filter label** | **any join to `s_skill_matrix`, `s_users_skills` or a competency table — none exists in `Api/Performance/`** |
| **7** · Competency → Recruitment | Q-D1 recorded the read as intended | nothing | **zero references to `s_user_skill_jobrole` / `s_jobrole_skills` in `Api/Talent/` or `talent_*`** |

### Why this is its own gap and not three

**A reader of the code would conclude these modules are connected.** The
vocabulary is there — a goal category called *competency*, a filter labelled
*Competency*, a field called *Learning Resources*. **Each is a label with no
referent.**

**The 9-box grid is the clearest casualty:** it has performance on one axis and
**nothing to put on the other**, because Performance has never been able to read a
capability measurement.

**This is the demand-side counterpart to `G-DATA-06`.** G-DATA-06 says the
relationships that *do* exist are joined by string; **G-FLOW-26 says three of the
relationships the product is sold on do not exist at all** — they are named and
not built.

**Connections:** `TL-02` (Performance), `TL-03` (Recruitment), `L-08` (LMS).

---

## FIX ORDER FOR THE REMAINING TENANT LEAKS — by DATA CLASS, not route count

**Decided 2026-08-06.** Route count is the wrong ordering: it optimises for
closing many at once rather than for closing the worst first.

| Tier | Data class | First items |
|---|---|---|
| **1** | **Candidate / personal data** | **`talent_interviewpanelController`** — interview panel records cover **candidates: people outside the company who never agreed to be in the system.** Once Q-D4's portal exists this is external PII and a leak is a **regulatory** matter, not only a commercial one. Then the other three C27 Talent controllers |
| **2** | **Payroll-adjacent** | `PayrollController` ✅ **done (D-004)**; `HrmsLeaveController`, `ApplyLeaveController`, `LeaveTypeController`, `LeaveSummaryReportController` |
| **3** | **Credentials / integrations** | `ExcelAutomationAgentController@credentialStatus` — reports on **another tenant's integration credentials** |
| **4** | **Competency and learning content** | `skillLibraryController` ✅ **done (D-003)**; `skillcontroller`, `assignmentController`, `courseController`, the rest |

**`talent_interviewpanelController` goes first among everything remaining**, ahead
of `assignmentController` (6 routes) and `HrmsController` (3).

---

## G-ORG-01 — the no-cycle guarantee is theoretical until every write path calls it · **S2**

**`ReportingLineValidator` exists (`f293edb0`) and nothing calls it.**

MySQL cannot express "no cycles", so the guarantee lives in application code — which
means it holds **only where the code runs**. Every path that sets
`reporting_manager_id` must call `canAssign()` first:

| Write path | Must call `canAssign()` |
|---|---|
| Employee create | ☐ |
| Employee edit | ☐ |
| Onboarding (assigning a new hire to a manager) | ☐ |
| **Bulk import** | ☐ — **the most likely to create the first cycle** |
| Any admin/org-chart screen | ☐ |

> **A validator nothing calls is documentation.** The first bulk import that sets
> managers without it creates exactly the cycle the validator was written to
> prevent, and team-scope resolution stops terminating.

**Tracked as plan item F-05a**, with "must call `canAssign()`" as an acceptance
criterion on each path.

---

## G-ORG-02 — the role model has nobody in six of its nine roles · **S3, by design for now**

**0 of 387 users have a `reporting_manager_id`**, and six of the nine roles were
created empty. Both are **correct** — the columns are new and nothing has assigned
them yet.

But the consequence is worth stating: **reporting coverage is 0%, so every
manager-dependent flow is gated off**, and a role model with nobody in it
demonstrates nothing. Slice 2's demo ("three roles, three different products")
needs users actually holding those roles, and Slice 3's manager-confirmation step
needs a reporting line that exists.

**The assignment mechanism — bulk and individual, for `reporting_manager_id` and
`head_user_id` — is tracked as plan item F-05b.** It was not in the plan before
this review; it is now.

---

## G-SEC-12 — caller-supplied audit provenance · **S1** · ✅ **CLOSED 2026-08-07** (`d70a204c`)

**`created_by` / `updated_by` taken from the request body.** S-3 found the pattern
**33 times**; `PayrollController` lines 167 and 238 are two, now fixed.

### Why this is a different class from the tenant leaks

> **A leak exposes data. This CORRUPTS THE RECORD OF WHO DID WHAT** — the evidence
> you would rely on when investigating a leak.
>
> A caller can attribute their own write to another user, and the audit trail
> records it **as fact**. Nothing looks wrong.

### ⛔ THIS BLOCKS THE EVENT STORE

`05-data-flow-contracts.md` assumes `actor_id` on every event is trustworthy. **If
actor identity can be caller-supplied anywhere, the event store inherits a
corrupted audit trail on day one — the exact thing it exists to provide.**

**Sequenced BEFORE the event store in the Gate D order.** Recorded in both
documents.

### Required

1. **The complete verified list (R6).** 33 candidates, hand-classified **IDENTITY** (must come from the token) vs **SUBJECT** (legitimately supplied — *"generate this employee's payslip"*). Same method that worked on PayrollController: read each site, trace what the value feeds.
2. **Fix shape:** mirror `payrollActorId()` — token first, session fallback, **never request input**.
3. ✅ **DONE.** The rule cleared **all 76 mechanically — zero ambiguous, zero hand-reads needed.** ⚠️ **Scope was 76 sites across 16 files, not 33** — S-3's figure counted only `created_by`; this covers every provenance column. **The estimate was low by 2.3×.** Fixed with `g2gActorId()` per file. Re-scan: **0 remain.**

---

## G-MAP-01 — the "one-line fix" does not exist · **re-costed**

Checked before changing anything. `QuickCreateKind`
(`services/competency/command-center.ts:111`) has **five** kinds —
`competency`, `framework`, `assessment`, `certification`, `development-plan` —
and `CREATE_ENDPOINTS` has the matching five. **There is no `role-mapping` kind,
and no create endpoint for it.**

So *"point the button at the right handler"* is impossible: **there is no handler
to point at.** That is the same finding from the other side — role mapping has no
create path, and the button is bound to `framework` because that is the only thing
available.

**RESOLVED 2026-08-06: the button was REMOVED**, with approval and an R8
checklist (`g2gv0` commit `cb2f6a5`). Not disabled, not annotated — **a control
that quietly does the wrong thing is worse than an absent one**: the user asked for
a role mapping, got a framework, and was told it succeeded. **M-03 is its
reinstatement** and it stays gone until the create path exists.

**M-03 stands as S–M**, and its real content is confirmed: build the create path
(surfacing `SchoolSetupController.php:392-408`'s existing bulk insert), then wire
the button to it.

---

## G-OPS-01 — the trait behind both shipped security fixes was untracked · **S1 (near-miss, now closed)**

**Found by an accident, not by a check.** A mangled shell command truncated
`00-progress.md` to zero bytes. It was untracked in git, so nothing could restore
it — which prompted an audit of what else was unversioned.

> **`ResolvesApiIdentity.php` — the F-01 trait that BOTH D-003 and D-004 depend on
> — was untracked.**
>
> Had it been lost the same way, **the two shipped tenant-isolation fixes would
> have silently reverted.** The calling code would still compile. The controllers
> would still respond. `skillLibraryController::competencyLibraryContext()` would
> still return an array. **The leaks would simply reopen, with no error anywhere.**
>
> **Only a C23 re-run would have caught it — and only if someone thought to run
> one.**

Four more load-bearing artefacts were in the same state: `RequireApiToken`,
`RequireProfile`, the `s_skill_matrix` alignment migration, and all three Phase 1
audit scripts.

### Net

**A corrupted tracking file triggered an audit that found five load-bearing
artefacts one mistake from disappearing. That is a better outcome than the accident
cost.**

### Closed by

| | |
|---|---|
| `fb284a06` | 87 Phase 3 files |
| `2849500e` | the Phase 1 security artefacts |
| `732f1ce` (g2gv0) | the frontend halves of D-001 and D-002, which were **not even committed** |
| **R18** | `docs/phase3/` is committed after every write to `00-progress.md` |

### ⚠️ Still open — committed is not backed up (C39)

| Repo | Remote | State |
|---|---|---|
| `hp_erp` | `github.com/rajeshrafaliyatriz/hp_erp.git`, tracking `origin/Milan-2` | **10 unpushed commits.** All of Phase 3 exists only on this machine |
| `g2gv0` | `github.com/zeeltank/g2gv0.git`, branch `milan1` | Now committed; **1 unpushed** |

### C41 — the same exposure, twenty times the size

**75 modified files sit uncommitted in `hp_erp`'s working tree** — the Phase 1/2
work. An untracked trait was one accident from silently reverting two shipped
security fixes; **75 uncommitted files in the same working tree is that exposure
at twenty times the scale.**

**They touch security artefacts.** The modified set includes
`ApprovalController`, `CertificationController`, `CompetencyController`,
`LmsGovernanceController`, `AJAXController`, `JobroleApiController` and the
`Resolves*Context` traits — i.e. **the F-01 tenant-resolution work itself**.

**Not mine to commit.** Raised so it can go to whoever owns them.

**Exposure is reduced, not removed.** A lost machine or a bad reset still takes
Phase 3 with it. **Pushing is Triz's call** — `hp_erp` carries 8 unpushed commits
that are not mine, and pushing is outward-facing.

---

---

# ⭐ G-SEC-07 — THE PRODUCT HAS NO WORKING PERMISSIONS · **S1** · THIRD AND FINAL STATE

> ## THE FINDING, worded so it can be repeated verbatim
>
> **The Next.js product's sidebar reads `tblgroupwise_rights_g2g` via
> `tblmenumasterG2gController::displaySidebarMenu`, filtered by `profile_id`, with
> absence denying (`?? 0`). `can_view = 1` on all 4,879 rows and
> `can_add` / `can_edit` / `can_delete` = 0 on all of them. Every profile sees the
> same 157 menus and no profile holds any action right.**
>
> **THE PRODUCT BEING SOLD HAS NO WORKING PERMISSIONS; roles differ in name only.**
>
> The seeder's placeholder intent is real, but **it was never replaced and the
> product ships on it.**

## The evidence chain, one link per step

| Step | Artefact |
|---|---|
| Next.js calls | `/user/ajax_sidebar_menu_g2g` — `services/navigation/sidebar.ts:41` |
| Route | `routes/user.php:44` |
| Controller | `tblmenumasterG2gController::displaySidebarMenu` |
| Menus from | `tblmenumaster_g2gModel` |
| **Rights from** | **`tblgroupwise_rights_g2gModel`, `where('profile_id', …)`** |
| Applied as | `if (! $this->canView(...)) { continue; }` — **a hard filter, not decoration** |
| Absence means | `return ($rights->can_view ?? 0) == 1;` — **deny** |

## Version history — both prior states, struck through

| Date | State | Why it was wrong |
|---|---|---|
| ~~2026-08-05~~ | ~~**S1** — "the rights matrix carries no information; enforcing it would grant everyone everything"~~ | **Right conclusion, incomplete evidence.** It never established which interface read the table |
| ~~2026-08-07 (morning)~~ | ~~**S3** — "the live table is differentiated; the uniform `can_view=1` is a deliberate placeholder; 4b is invisible to users"~~ | **WRONG.** It found differentiated data in `tblgroupwise_rights` and assumed that was the live table. It is the **Blade** product's. And it let the seeder's stated *intent* stand for the product's *behaviour* (**R10c**) |
| **2026-08-07 (final)** | **S1 — as stated above** | Traced from the frontend call to the controller query. One endpoint, one controller, one table |

**Three states of one finding is untidy. Hiding two of them would be worse.**

## What follows

**4b is not curation. It is the fix**, and it is **the most visible change in the
plan** — the first thing in this phase a person will see.

### Two asymmetries the diff must separate

| Direction | Risk |
|---|---|
| **Action rights** (`add`/`edit`/`delete`) | **PURELY ADDITIVE.** Nothing holds them today, so populating can only **grant**. **Low risk** |
| **`can_view`** | **SUBTRACTIVE.** Every profile currently sees all 157 menus, so real rights **REMOVE** menus from every profile **including Administrator**. **This is where the risk sits, and what the review gate is for** |

---

# Q-A5 — EXTERNAL DESTINATIONS, ALL THREE IN ONE PLACE

**For a security review.** Every destination outside our origin that the product
loads or links to, and what leaves with the request.

| Screen | Host | What leaves our origin |
|---|---|---|
| Taxonomy Ontology (menu 43) | **`skill-ontology-neo4j.vercel.app`** | **`sub_institute_id` in the URL**, plus the customer's IP, **on every page load** |
| Skills link | *(recorded under Q-A5)* | — |
| Pal link (menu 187) | *(recorded under Q-A5; `status=0`, removed from the seed)* | — |

**The ontology iframe carries `sandbox` without `allow-same-origin` and
`referrerPolicy="no-referrer"`.** Those are right and are not being
re-litigated — **but they do not answer a procurement question about data leaving
our origin on every page load.** The exposure is the **tenant id and the request
itself**, not the session.

**C-T3-ONT (M) is the real fix, not an enhancement** — it removes the external
host and the tenant id from the URL *as well as* making the graph true. Blocked
on F-07b, as scheduled.

---

# G-NAV-02 — THE SIDEBAR ENDPOINT TAKES profile_id FROM THE REQUEST · **S2**

Found while building the R9 harness, not by looking for it.

`tblmenumasterG2gController::displaySidebarMenu:32` reads
`$profile_id = $request->input('profile_id')` and the guard (`:326`) checks only
that **a** valid token was supplied — **never that the caller holds that profile.**

Any authenticated user can request **any** profile's sidebar.

**RAISED TO S2 from S3.** The impact reasoning — structure, not data — is right,
but it under-weighted what structure gives away: **an employee can enumerate which
screens every role holds.** That is **reconnaissance** — it maps where the
interesting endpoints are, and it erodes the value of the rights that were just
applied.

**Effort is one line, in G-SEC-12's family: resolve `profile_id` from the token's
user, never from the request.** Fixed with the security queue, not after it.

> **THIS DEFECT IS WHY ONE TOKEN COULD TEST NINE ROLES.**
> When it is fixed the R9 harness needs **nine tokens**, one per role.
> **The harness must be updated in the same commit**, or verification breaks
> silently — passing because it can no longer ask the question, not because the
> answer is good.

**Same class as the subject-from-request findings**, and it is the reason the R9
harness can vary `profile_id` on one token to check nine roles. **The defect is
what makes the test convenient**, which is worth stating plainly rather than
quietly relying on.

---

# G-SEED-01 — THE MARK PARSER READ QUALIFIER TEXT AS PERMISSIONS · **S1, CAUGHT PRE-APPLY**

**Found by inspecting Employee's AFTER list by name** — Learning Dashboard showed
`V E` where §3.x says `V (self)`. The gate that exists for exactly this caught it.

The parser stripped punctuation but kept the qualifier's **letters**:

| §3.x cell | Parsed as | Granted |
|---|---|---|
| `V (self)` | `VSELF` | **V + EDIT** |
| `V (member)` | `VMEMBER` | **V + EDIT** |
| `V (own punch)` | `VOWNPUNCH` | **V + CREATE** |
| `V (org — basic fields)` | `VORGBASICFIELDS` | **V + CREATE + EDIT + DELETE** |

**The employee directory — the screen denied precisely because it exposes too
much — was being seeded with full create, edit and delete.** Across all four
qualified roles, not just Employee.

### Root cause, and it is the same one as G-RBAC-01

**The qualifier and the mark live in one cell.** Every qualifier is data the table
cannot express, sitting in the same string as data it can. The parser had no way
to tell them apart because the format does not distinguish them.

### Fix, in three parts

1. **Strip the qualifier before reading marks**, in both forms it takes —
   parenthesised `V (self)` and comma-separated `V, self-register`.
2. **Whitelist the result to `VCEDAX`.** An unrecognised letter means a format
   this parser has not seen, and silently granting on it is the bug itself.
3. **Report anomalies and grant nothing** for them.

### What the whitelist then caught, which nothing else would have

```
3.1 | Employee Directory | reporting_manager => "V (org basic) + V full (team)"
3.1 | Employee Directory | department_head   => "V (org basic) + V full (dept)"
```

**A compound mark**: org-wide basic fields *plus* full records for the caller's
team. **Two different scopes in one cell.** It cannot be expressed by a menu
boolean at all, so it grants nothing — the same verdict Employee Directory already
has for Employee, now reached mechanically for the other two roles.

**Consequence, stated plainly: Reporting Manager and Department Head lose the
Employee Directory too** (47 and 48 menus, down from 49 and 50). Same reasoning as
the Employee decision, and it lands in the same place — **§3.8**.

---

# G-SEED-02 — DISABLED MENUS WERE BEING GRANTED · **S2, CAUGHT PRE-APPLY**

Name matching was blind to `status`. `Certifications` matched **menu 25**
(`status=0`, under User Management) as well as **menu 158** (`status=1`, the
Competency screen actually meant) — and menu 158 was denied by the qualifier read
while **menu 25 was granted**.

A disabled menu does not render, so the grant was **invisible today** and would
**light up silently the day the menu was enabled**. `Holiday Master` (167) the
same.

Fixed by excluding `status != 1` from matching. **Gate A totals did not move**,
which confirms the defect was latent rather than live.

Three screens moved to `unmapped = DENIED` as a result, including **Skill Gap
Analysis** — so the generator now reaches G-RBAC-02's verdict **on its own**,
independently of the read that found it.

---

# G-LMS-SEC-01 — LMS ASSIGNMENT ENDPOINTS WERE UNAUTHENTICATED · **S1** · **FIXED**

> ## THE FIRST UNAUTHENTICATED EXPOSURE OF THE PHASE
>
> **Every other finding in this register required a valid token.** Tenant
> breaches, forged provenance, missing ownership checks, over-broad grants - all
> presuppose an authenticated caller who then reaches too far.
>
> **This one required nothing.** No token, no account, no session. An anonymous
> caller could read the enrolment register and reach the approval path.
>
> **The distinction matters to a buyer**, and it is why this outranks findings
> with a larger blast radius: everything else is a privilege question. This was
> an open door.

Queue item 1. `assignmentController` carried **four** stacked defects, and the
worst was not the one it was queued for.

### 1. Authentication was OPTIONAL — the one that matters

```php
private function validateToken(Request $request) {
    $type = $request->type;
    if ($type == "API") { ...check the token... }
    return null;              // <- no type, no check, request proceeds
}
```

**Omitting the `type` parameter skipped authentication entirely.** These are API
routes (`routes/api.php:615-625`) and **none of them carries middleware**, so this
method was the only control on the endpoint.

**Proven through the full stack before the fix:**

| Request | Result |
|---|---|
| `GET /api/lmsAssignment`, **no token, no `type`** | **HTTP 200, 20,777 bytes** of learner names and course names |
| `POST /api/lmsAssignment/review/{id}`, **no token** | reached the record lookup — **404 only because the id did not exist** |

An anonymous caller could **read the enrolment register and approve enrolments.**

### 2. The role came from the request

`$request->input('user_profile_name')` — so `user_profile_name=admin` granted
review rights.

### 3. Failure-open on an empty profile

`if ($profile !== '' && !str_contains(...))` — **omitting the parameter passed the
guard altogether.** This is what the item was queued for, and it is the third
defect, not the first.

### 4. Tenant from the request

`$request->sub_institute_id ?? $request->header(...)`, at **9 sites**.

> **All four are the exact defects `ResolvesLmsIdentity`'s own header records as
> CLOSED for the other LMS controllers.** They were still live here — so this is a
> **regression as well as a hole**, and evidence that the G-SEC-12 sweep was
> scoped by controller, not by defect.

### Fixed

The controller now `use`s `ResolvesLmsIdentity`. `validateToken()` delegates to
`guardLmsToken()` — **always, with no opt-out**; both profile guards delegate to
`guardLmsProfile()`, which resolves the role from the **token's** user and
**refuses an unresolvable profile**; all 9 tenant reads go through `lmsTenantId()`.

### Verified — and the two questions kept separate

**WHO MAY CALL THIS** — closed:

| | before | after |
|---|---|---|
| no token, no `type` | **200, 20,777 bytes** | **401** |
| forged `user_profile_name=admin` | **200** | **401** |
| `review` as employee | permitted | **403** |
| `review` as administrator | permitted | **404** (passes the gate; record absent) |

**WHICH ROWS COME BACK** — **NOT closed, and route/auth guards do not answer it:**

| | after |
|---|---|
| `GET /api/lmsAssignment` as **employee** | **200, all 48 rows** — the whole tenant's enrolment register |

**An employee still sees every learner's enrolments.** That is a **row-scope**
question of exactly the kind G-RBAC-01's 121 qualifiers are about, and it is
**unresolved**. Authentication being fixed must not be read as the screen being
safe.

---

# G-HARNESS-01 — IDENTITY LEAKS BETWEEN REQUESTS IN A SINGLE-PROCESS HARNESS · **METHOD DEFECT**

Reusing one HTTP kernel for several requests in one PHP process **caches the first
request's resolved identity and reuses it for every later request.**

**Demonstrated, not inferred** — the same two calls, order reversed:

```
order: emp-first     employee -> 403      admin -> 403      <- admin wrongly refused
order: admin-first   admin    -> 404      employee -> 404   <- employee wrongly allowed
```

**Whoever goes first decides both outcomes.** A production request is a fresh
container, so this is a **harness defect, not a product defect** — but it silently
falsifies any identity-dependent multi-request check.

### What it affects, stated precisely

| Check | Affected? |
|---|---|
| **G-LMS-SEC-01 verification** | **Was affected — re-run one request per process.** The table above is the corrected result |
| **G-COMP-SEC-01 verification** | **Re-run and unchanged** (own 200 / colleague 403 / cross-tenant 404). The leak held identity at the intended caller throughout, so the original result was right — **by luck, not by design** |
| **R9 nine-role sidebar check** | **Not affected.** It varies `profile_id` in the request, not the identity, and one acting user is intended throughout. Its nine counts differ from each other and match Gate A independently |
| **C23 tenant guard** | **FLAGGED, not cleared.** It issues two requests per URI in one process. Its calls carry **no token**, so there is no identity to cache — but **C24's release gate rests on this guard**, so it gets its own verification rather than an argument. Not re-run here |

**Rule going forward: one request per process for anything identity-dependent.**

### The same class, found alongside it - the wrong model

`App\Models\User` is backed by the **`users`** table. Real tokens are issued
against `App\Models\auth\tbluserModel`, backed by **`tbluser`**:

| tokenable_type | tokens |
|---|---:|
| `App\Models\auth\tbluserModel` | **4,511** |
| `App\Models\User` | 14 |

The first tests minted tokens on `App\Models\User` - **a different table, with
different ids and a NULL `user_profile_id`**. It produced a plausible false
"admin regression".

> **A test against the wrong model proves nothing about the running system.**
> Both affected verifications were re-run against `tbluserModel` and hold.

---

# C23 HARNESS — VERIFIED, NOT ARGUED

**C24's release gate rests on this guard, so it was measured rather than reasoned
about.**

### The argument I gave was wrong

I said C23 was probably unaffected by G-HARNESS-01 "because its calls carry no
token". **It does carry one.** `c23-tenant-guard.php:34` defines `TOKEN_A` and
`call_route()` sends it **both** as a request parameter **and** as a Bearer
header. The question was open, not closed.

### Method

For a sample stratified across **every verdict class**, re-run the same
baseline/attack pair **one request per process** and compare against the recorded
verdict. Agreement on FAIL and PASS is what clears the guard; any disagreement
invalidates it.

### Result — 20 of 23 agree

| Recorded | Isolated re-run | Agreement |
|---|---|---|
| **PASS** ×8 | identical body ×8 | **8/8** |
| **FAIL** ×5 (`job-role-tasks`, `jobroletexonomies`, `skills`, `role-similarity`, `audit/export`) | **CHANGED** ×5 | **5/5** |
| **VACUOUS** ×3 | identical ×3 | **3/3** |
| **LEAK-NOSCOPE** ×3 | identical ×3 | **3/3** — correct: LEAK-NOSCOPE *is* identical-but-carrying-tenant-B-markers |
| **FAIL** ×3 (`api/lmsAssignment/stats`, `/learners`, `/enrollments`) | identical | **explained, see below** |

### The three disagreements are my own fix, not a harness defect

All three are `assignmentController` routes. **G-LMS-SEC-01 replaced its nine
`$request->sub_institute_id` reads with `lmsTenantId()` earlier in this same
session.** The recorded run predates that change. The route now ignores the
supplied tenant, so the two responses are identical — which is the fix working.

**Incidental confirmation:** the tenant half of G-LMS-SEC-01 is now independently
verified by C23's own instrument, not only by my targeted test.

### Why it holds

Both requests in a C23 pair use the **same token**, so a cached identity is the
**same** identity and the leak has nothing to change. The tenant claim under test
travels in a **request parameter**, which is not what gets cached.

**VERIFIED — with its limit stated:** this is a **23-route stratified sample of
912**, not an exhaustive re-run. It covers every verdict class and both
directions. **C23's verdicts stand, and C24's gate may rest on them.**

---

# G-SEC-13 — `if ($type == "API")` IS A SIGNATURE, NOT AN INCIDENT · **S1** · **CANDIDATES (R6)**

`PayrollController` had it. `assignmentController` had it. Both times it meant the
same thing: **THE CALLER DECIDES WHETHER CHECKS RUN.**

Swept across the whole codebase rather than treated as two incidents.

| Measure | Count |
|---|---:|
| Controllers where a token check is gated on `$type == "API"` | **46** |
| Routes reaching them | **420** |
| **Routes with NO auth middleware of any kind** | **132** |

**132 routes** whose only authentication control is a check the caller switches
off by omitting one parameter.

### Top of the candidate set

| Controller | Routes with no middleware |
|---|---:|
| `AJAXController` | 18 |
| `libraries\skillLibraryController` | 17 |
| `lms\assignment\assignmentController` | **11 — PROVEN exposed, now fixed** |
| `talent\talent_interviewschedulescontroller` | 10 |
| `talent\talent_jobapplicationcontroller` | 10 |
| `talent\talent_jobpostingcontroller` | 8 |
| `libraries\jobroletaskcontroller` | 7 |
| `libraries\jobroletexonomycontroller` | 7 |
| `Api\skillcontroller` | 7 |
| `dashboards\SkillDashboardController` | 7 |

**CANDIDATES, not findings (R6).** One is proven — `assignmentController` returned
**HTTP 200 and 20,777 bytes to an anonymous caller**. The other 121 are
**presumed dangerous until read or probed**: the signature is identical and the
middleware is absent.

**Proxy named (R10):** *controller contains `$type == "API"` near a token check*
**and** *no route middleware matches `/auth|sanctum|profile|token/`*. A controller
passes the proxy and is still safe if some other guard runs first; it fails the
proxy and is still dangerous if its check is weak for another reason. **The
behavioural probe is the settling instrument.**

### CORRECTION — the probe did not hang, and the numbers below supersede the 132

I reported the first probe as **hung, blocking on an outbound call**. **That was
wrong.** It died with `Allowed memory size of 536870912 bytes exhausted` before
printing anything. Diagnosed from its own output once it completed, not guessed.

**The 132 is a route-level count across ALL verbs, including `{id}` routes that
cannot be probed blind.** It is the right number for *"routes with no auth
middleware reaching the signature"* and the wrong number for *"routes an anonymous
caller can reach"*. Both are stated so neither is quoted for the other (R10).

### The behavioural result — chunked so one fatal costs one chunk

| Measure | Count |
|---|---:|
| GET routes, no `{id}`, no auth middleware | **57** |
| Probed to completion | **52** |
| **Died mid-request** | **1** |
| **Answered 200 to an anonymous caller** | **4** |

**The four:**

| Route | Body |
|---|---|
| `api/kpis` | `{"status":true,...,"overallSkillCoverage":0,...}` — **a live metrics endpoint answering anonymously**. Zeroes here because this tenant has no data, not because it refuses |
| `DeepSeekChat` | Proxies to the DeepSeek API and returned its upstream error. **Anonymous access to a paid AI proxy** — cost and abuse, not disclosure |
| `api/ai-generated-assessment/question/index` | `"data":[]` — structure works, table empty |
| `api/candidate` | `"data":[]` — **re-probed against tenants 7 (150 rows) and 3 (107 rows) and still returned 0**, so a further filter is applied. Reachable, not proven to disclose |

**Honest reading: reachable surface is proven; record disclosure is not** — apart
from `assignmentController`, which is proven and fixed. The 121 remaining
candidates are **not** thereby cleared: `{id}` routes and all POST/PUT/DELETE
routes were never probed, and that is where `assignmentController`'s approval path
sat.

---

# G-SEC-15 — AN ANONYMOUS REQUEST CAN EXHAUST SERVER MEMORY · **S1**

> **RAISED FROM S2.** It discloses nothing, which is why it first read as S2 —
> but **availability is a security property.** No credential, no rate limit to
> defeat, no skill: one URL, repeated from a browser. *"Anyone on the internet can
> take the API down"* ends a procurement conversation as fast as a data leak.
>
> **Fix is BOTH, not either: auth middleware AND a bounded result set.**
> Auth alone leaves an authenticated user able to do it; a bound alone leaves the
> endpoint open.

`AJAXController@getSkillCompetency`, route `getSkillCompetency` — **no auth
middleware, no `{id}` parameter**.

An unauthenticated GET **exhausted a 512MB PHP memory limit** inside
`Connection::execute()`. It is the one route of 57 that killed its own probe
process.

**THREE problems in one route**, and any one alone leaves it exploitable:

1. **No authentication** — declared in `routes/web.php` with the comment *"Rajesh for only API temporary created for data fetch"* and no middleware.
2. **Unbounded result set** — a four-way join over the largest tables ending in `->get()` with no limit.
3. **Tenant from the request, defaulting to a hardcoded `?? 2`** — an absent parameter silently served tenant 2's data.

### FIXED — all three, because the fix had to be both and turned out to be three

`resolveApiIdentity()` supplies authentication **and** tenant; the query is bounded
and paginated (`limit` default 500, hard ceiling 2000, with `offset`, and
`limit`/`offset`/`count` returned so truncation is visible rather than silent).

| | before | after |
|---|---|---|
| anonymous GET | **512MB memory exhaustion** | **401** |
| authenticated GET | unbounded | **200, 500 rows, paginated** |
| absent `sub_institute_id` | served **tenant 2** | resolved from the token |

---

# G-SEC-16 — UNBOUNDED RESULT SETS ARE SYSTEMIC, NOT SPECIFIC · **S2** · **CANDIDATES (R6)**

The question *"do other routes load unbounded sets?"* is **different from
disclosure and had never been asked.** Asked now.

**108 GET routes** with no auth middleware reach a method that calls `->get()` with
no `limit`, `paginate`, `take`, `first`, `chunk` or `cursor` anywhere in its body.
The heaviest carry **three joins**:

| Route | Action | Joins |
|---|---|---:|
| `api/skill-development/progress` | `SkillDevelopmentController@getSkillProgress` | 3 |
| `api/leave-distribution` | `HRITDashboard\LeaveDistribution@leaveDistribution` | 3 |
| `api/leave/distribution` | `Leave\LeaveDistributionApiController@index` | 3 |
| `api/candidate` | `talent\candidate\candidateController@getCandidate` | 3 |
| `api/feedback` | `talent\feedback\feedbackController@getAllFeedback` | 3 |
| `api/pending-feedback` | `talent\feedback\feedbackController@getPendingFeedback` | 3 |

### Read this carefully — "no auth middleware" is NOT "unauthenticated"

**The controller's code is not the endpoint's behaviour**, and the inverse applies
here: **most of these controllers authenticate INSIDE the controller** through
`ResolvesApiIdentity`. Only **4 of 57** probed routes answered an anonymous caller.
So:

- **the anonymous-DoS surface is small** — G-SEC-15 was the live instance;
- **the authenticated-DoS surface is 108 routes wide**, and that still matters,
  because **any logged-in employee can exhaust server memory** on a tenant with
  real data volumes.

**This is why the fix had to be BOTH.** Auth alone would have left 108 routes
reachable by every authenticated user in the product.

**Proxy named (R10):** *method body contains `->get()` and no bounding call*. Wrong
in both directions — a naturally-small `WHERE` bounds without a `limit` (false
positive), and a method can paginate one query while another runs unbounded (false
negative). **Candidates, not findings.**

---

---

# G-SEC-14 — THE G-SEC-12 SWEEP WAS SCOPED BY CONTROLLER, NOT BY DEFECT · **S1**

**The third time a sweep's SCOPE rather than its LOGIC produced the miss.**

All four defects in `assignmentController` are ones `ResolvesLmsIdentity`'s header
records as **CLOSED**. They were closed *in the controllers the sweep visited*. The
sweep asked *"is this controller fixed?"* — never *"where else does this shape
occur?"*

### Re-swept by DEFECT SHAPE. Live instances, in files already "fixed"

| Site | Shape | Why it is live |
|---|---|---|
| **`libraries\skillLibraryController:300`** | role from request | `if ($request->user_profile_name == "Admin") { $appStatus = 'Approved'; }` — **a caller sets their own submission to Approved by passing `user_profile_name=Admin`.** A self-approval bypass. **This file was fixed by D-003 — for TENANT, not for role** |
| **`Payroll\PayrollController:1304-1307`** | role from request | `$user_profile = $request->user_profile_name` |
| **`Payroll\PayrollController:2116`** | role from request | `$userProfile = $request->user_profile_name`, passed straight into `employeeDetails($sid, …, $userProfile, $profileUserId)` — **an attacker-supplied role decides which employees are returned.** Sits **two lines** from `payrollTenantId()` and `payrollActorId()`, the D-004 fixes |
| `lms\lms_apiController:741` | role from request | candidate, use not yet traced |
| `lms\questionpaperController:57` | role from request | session-first, request as fallback |

**`PayrollController:2116` is the clearest evidence of the class:** the identity and
tenant on that line were fixed, and the **role on the next line was not** — because
the sweep was looking for tenant and actor, not for role.

### The correction to method

**A sweep must be defined by the DEFECT SHAPE and run across the whole codebase,
never by a list of files.** A file is "fixed" only for the shapes that were
searched in it.

---

# G-AUTH-01 — AUTHORIZATION MATCHED ON DISPLAY-NAME SUBSTRINGS · **S2** · **FIXED**

`RequireProfile::profileMatches()` compared the caller's **profile display name**
against the route's argument **by substring**. `str_contains('reporting manager',
'manager')` is true, so a Reporting Manager passed a gate written for HR Managers.

**Not closed by side effect.** The competency ownership check stops today's
instance reaching anything, but **the matcher would do it again** for any future
role whose name contains another's — `hr_executive`/`hr_manager`,
`department_head`/`head`. **This is precisely the failure `role_key` was
introduced (D-010) to end: authorization must key on a stable identifier, never
on wording a tenant can edit.**

### Fixed — exact match on role_key

Route arguments keep their vocabulary (`admin`, `hr`, `manager`) so no route file
changes; an `ALIASES` map resolves each to the `role_key`s it means, compared with
`in_array(..., true)`. An alias the map does not know **grants nothing** rather
than falling through to a looser comparison.

**13 profiles predate `role_key` and 4 of them have users**, so they resolve
through `LEGACY_NAMES` by **exact** name — not substring.

### Verified by differencing old against new, on the real arg-sets

Both arg-sets actually used in `routes/` (`profile:admin,hr` ×17 and
`profile:admin,hr,manager` ×6), across all 112 profiles:

**Exactly one profile decides differently, and it has zero users** — id 38
*"Deparment Administrator"*, which passed only because its name contains
`admin`. **That is the collision being removed, and a department administrator is
not an institute administrator.** Denied deliberately, recorded so it is a
decision and not an oversight.

### The same matcher elsewhere — swept, not assumed

| Site | Status |
|---|---|
| `RequireProfile:89` | **FIXED** |
| `ResolvesLmsIdentity:101` (`guardLmsProfile`) | substring — **open** |
| `LmsLearningController:1537` | substring — **open** |
| `lms/assignment/assignmentController:376,441` | substring — **open**, and worse: `$profile !== '' && !str_contains(...)` means **an empty profile is treated as permitted**, the exact bug `ResolvesLmsIdentity`'s header says was closed. Blade surface, so out of the product scope — but it is the same live database |

Queued behind the security items rather than fixed here: each needs its own
old-vs-new difference check, and batching them would hide which one moved what.

---

# G-COMP-SEC-01 — ANY EMPLOYEE CAN READ ANY COLLEAGUE'S COMPETENCY PROFILE · **S1** · **FIXED**

> ## CORRECTION — THE WRITE CLAIM WAS WRONG
>
> I reported this as **read AND write**, and called it *"silent corruption of the
> product's core asset"* — worse than payroll. **The write half was wrong.**
>
> **All five write routes already carried `middleware('profile:admin,hr,manager')`**
> — `addSkill`, `updateSkill`, `saveNotes`, `storeEvidence`, `deleteEvidence`
> (`routes/api.php:347,348,351,355,356`). `RequireProfile` resolves the profile
> from the token's user and refuses an unresolvable one. **An ordinary employee
> could not raise their own ratings or lower a colleague's.**
>
> **How I got it wrong:** I read the controller and never read the route file.
> The controller genuinely has no ownership check — that part is accurate — but
> **a route-level guard is part of the endpoint's protection and I treated the
> controller as the whole of it.**
>
> **This is R10c in a new place: the controller's code is not the endpoint's
> behaviour.** The same mistake as taking a seeder's intent for its effect.
>
> **What remains, and it is still S1:** all **eight READ routes carry no
> middleware at all**, so any authenticated employee could read any colleague's
> full competency profile — skills, ratings, assessor, manager, notes, evidence,
> career path. That is exposure of the same kind as payroll, on the data this
> phase exists to build.

**The most serious finding of the build phase, and worse than payroll.**

`EmployeeCompetencyProfileController` takes the subject from the **route**, and
never compares it to the caller:

| Method | Line | Subject |
|---|---|---|
| `show(Request $request, $id)` | `:15`, filters at `:26, 95, 159` | `$id` — route parameter |
| `addSkill(Request $request, $id)` | `:238`, writes at `:260, 273` | `$id` — route parameter |
| `updateSkill(Request $request, $id, $matrixId)` | `:318` | `$id` — route parameter |

`competencyContext()` authenticates the caller and yields the tenant, so the
**tenant** boundary holds. **The ownership boundary does not exist** in the
controller — and on the eight read routes nothing else supplies it.

### Why it still ranks first

**Read exposure, on the data the whole phase is built to produce.** Competency
ratings are more sensitive than most HR fields: they drive promotion readiness and
succession shortlists, and an employee who can see a colleague's ratings can see
where they stand against them.

**And it is the screen that must come back first** — golden thread 1 cannot be
demonstrated to an employee until Competency 154–158 is re-granted, which cannot
happen until this is closed.

### Fix

`resolveApiIdentity()` **plus an ownership check on `$id`**, the same shape as
`ResolvesLmsIdentity`. Self-service reads and writes must resolve `$id` to the
caller unless the caller holds a role that legitimately acts on others.

### FIXED — 2026-08-10

`ResolvesCompetencyContext::competencySubject()` resolves the route's `$id` to a
subject the caller may act on, and **all 13 methods** call it — not only the
three originally named. Two checks, both required:

1. **the subject must be in the caller's own tenant**, checked first so an
   elevated caller cannot probe a cross-tenant id for existence;
2. **the caller must be the subject, or hold an elevated `role_key`.**

Keyed on `role_key` (D-010), not on a substring of the display name.

**`department_head` and `reporting_manager` are deliberately absent from the
elevated set.** Their legitimate scope is *my department* / *my team*, and neither
is evaluable while `reporting_manager_id` is NULL for every user (**G-ORG-02**).
Granting them org-wide access would be **wider than the grant being closed**.

**Verified through the real request path:**

| Request | Result |
|---|---|
| own profile | **200** |
| colleague's profile | **403** |
| absent / cross-tenant id | **404** |

**Side effect worth its own line.** `RequireProfile` matches by **substring**, so
`str_contains('reporting manager', 'manager')` is true — a Reporting Manager
passed the *write* gate for **any** employee in the tenant, not just their team.
The new ownership check closes that too, because `reporting_manager` is not an
elevated `role_key`.

**Re-grant of Competency 154–158 is now unblocked** — first on the re-grant list.

---

# G-CHAIN-01 — THE CHAIN NEITHER HALF SHOWS ALONE · **S1** · **CLOSED AT BOTH ENDS**

> **door → catalogue → raw interpolation**

| Hop | Site | What it contributed |
|---|---|---|
| **1. The door** | `CustomModuleController:74` | `table_name` validated as `required|string|unique` — **arbitrary characters accepted** — and the feature **creates tables from it** |
| **2. The catalogue** | MySQL `information_schema` | A table created from that string puts an **attacker-chosen identifier into MySQL's own catalogue** |
| **3. The raw statement** | `AJAXController:226` — ``DB::select("SHOW COLUMNS FROM `$tableName`")`` | `$tableName` comes from `SHOW TABLES`. **Safe ONLY because the catalogue cannot hold a backtick-bearing name** — which was true only while nobody could create one |

**Each hop is defensible on its own. The chain is not.** `AJAXController:226` was
reviewed and passed as *"the value comes from the catalogue, not the request"* —
**and that was true by accident**, dependent on a validation two files away that
did not exist.

**Closed at hop 1 (G-SEC-20, whitelist at the door) and hop 3's premise is now
guaranteed rather than assumed (G-SEC-21, validation at the point of use).**

**Recorded as its own entry because neither finding shows it.** It is visible only
by reading G-SEC-20 and G-SEC-21 together.

---

# SHAPE-01 — "TRUSTED BECAUSE OF WHERE IT CAME FROM" · **FILED, NOT SWEPT**

**Data treated as safe because of its SOURCE, when that source is itself fed by
user input.**

Two instances, both from this round, both where the safety argument was about
provenance rather than content:

| Instance | The argument made | Why it held |
|---|---|---|
| `CustomModuleController:225` | *"`table_name` comes from the database, not the request"* | **False** — the database got it from the request, unvalidated |
| `AJAXController:226` | *"`$tableName` comes from MySQL's catalogue"* | **True only by accident** — see G-CHAIN-01 |

> **"It is from the database" and "it is from the catalogue" have both now been
> used as safety arguments in this codebase, and one of them was only true by
> accident.**

**FILED as a known shape with these two instances. NOT swept** — the queue is long
enough, and the decision on when it gets a sweep is Triz's.

---

# G-SEC-22 — `tableDelete` HAD NO AUTHENTICATION AND NO TENANT SCOPING · **S1** · **FIXED**

Left open deliberately when G-SEC-20 was fixed, so an injection fix did not
quietly become an authorisation fix. Closed here.

### Reach chain (R20)

| Layer | Finding |
|---|---|
| Route | `routes/web.php:190` — `DELETE /custom-module/table-delete/{id}` |
| Group | `Route::group(['prefix' => 'custom-module'], ...)` — **prefix only** |
| Middleware | **`web` alone** — session and CSRF, **no `auth`** |
| Method | Took `$id`, found the row, dropped the table, deleted the row. **No tenant, no user, no auth check** |

### Fixed

`customModuleTenantId()` — token first (for the mobile/API callers `is_mobile()`
serves), session second (the rest of the controller reads the tenant from the
session at `:24`, `:69`, `:310`), **null when neither identifies the caller**.
`tableDelete` refuses on null, and both the lookup **and** the delete are scoped to
that tenant.

**Verified:**

| Request | Result |
|---|---|
| anonymous | **401 Unauthenticated** |
| authenticated, id not in the caller's tenant | **404 Module not found** |
| legitimate row | **untouched** — 1 row before and after |

---

# G-SEC-21 — STORED-THEN-EXECUTED: IT IS A DESIGN ISSUE, NOT A LIST OF SITES · **S1** · **FIXED**

**Bounded sweep, one question:** *does any DB-sourced string reach DDL, a raw
statement, or a dynamic table/column name?*

## Size — within the bound, so fixed rather than escalated

| Surface | Result |
|---|---|
| `Schema::create/drop/table/rename` taking a variable | **none** |
| `DB::statement` / `DB::unprepared` splicing a variable | **one** — `CustomModuleController:259`, already guarded by G-SEC-20 |
| Dynamic table names | **`CustomModuleController` + `DynamicModel`** — 21 uses, plus 6 external call sites |
| `DB::table($var)` elsewhere (`ApprovalController`, `LibraryController`, `DevelopmentPlanController`) | **not in scope** — the value comes from **hardcoded PHP maps**, not the database, and the builder wraps identifiers |
| `AJAXController:226` — `DB::select("SHOW COLUMNS FROM \`$tableName\`")` | `$tableName` comes from **`SHOW TABLES`**, i.e. MySQL's own catalogue — **not the request**. See the chain below |

## THE STRUCTURAL ANSWER — validation existed ONLY at creation

**No.** The custom-module feature validates `table_name` **nowhere except
`tableStore`**. Every downstream use — `Schema::hasTable`, `Schema::hasColumn`,
`DynamicModel::readRecords/readSingleRecord/createRecord/updateRecord/deleteRecord`
— **trusts the stored value.**

> **So it is a DESIGN issue, and the fix is validation at each execution site —
> not a per-site patch.**
>
> **Validation at the door is necessary and NOT sufficient**, because the door was
> open for the entire life of the feature and the values are already stored.
> G-SEC-20 proved the door was unlocked.

## FIXED — one guard, at the point of use

`DynamicModel::assertSafeTable()` — **every** dynamic table name passes through
it, and nothing else can set one:

- `setTable()` (so `initialize()` inherits it),
- all five static entry points.

A table name is an **identifier, never data**. Anything outside `[A-Za-z0-9_]`
throws — **loudly, not silently**: failing quiet would return an empty result that
reads like *"no records"*.

**Verified:**

| Input | Result |
|---|---|
| `tbluser` | allowed |
| `Z_probe;SELECT/**/1` (the G-SEC-20 payload) | **refused** |
| ``x`; DROP TABLE y; --`` | **refused** |
| `readRecords` / `deleteRecord` with a payload | **refused** |

## A CHAIN WORTH RECORDING, now broken at both ends

`AJAXController:226` interpolates a table name from `SHOW TABLES` into a raw
statement. That is safe **only because MySQL's catalogue cannot contain a
backtick-bearing name unless one was deliberately created** — and until G-SEC-20,
`table_name` accepted arbitrary characters and the feature created tables from it.

**Door (G-SEC-20) → catalogue → raw statement (`AJAXController:226`).** Closed at
the door and now at the point of use; recorded because the chain is only visible
when the two findings are read together.

---

# G-SEC-20 — SECOND-ORDER SQL INJECTION: ARBITRARY TABLE DROP · **S1** · **FIXED**

**The most severe finding of the phase.** One read settled it, as scoped.

### The question that decided it: CAN A USER INFLUENCE `table_name` AT CREATION?

**Yes.**

| Step | Line | What happens |
|---|---|---|
| Validation | `:74` | `'required|string|unique:...'` — **no character constraint** |
| "Sanitising" | `:82-84` | `str_replace(' ','_')` and a `Z_` prefix. **Nothing else** |
| Storage | `:99`, `:106` | `$customModuleTable->save()` — **saved BEFORE any table is created**, so the row persists whatever the caller sent |
| Execution | `:225` | `DB::statement('DROP TABLE IF EXISTS ' . $table->table_name)` |

**Not injectable where it executes; injectable where it is stored.** Exactly the
second-order shape.

### The accidental mitigation, and why it fails

`str_replace(' ','_')` defeats the naive `; DROP TABLE x` — the spaces become
underscores and the keywords break. **But MySQL accepts an empty block comment as
a separator, so a payload needs no spaces at all.**

### Proven by test, safely

```
payload            : Z_probe;SELECT/**/1
after sanitiser    : Z_probe;SELECT/**/1     <- survives intact, no space to replace
would execute      : DROP TABLE IF EXISTS Z_probe;SELECT/**/1
RESULT             : *** ACCEPTED - multi-statement executes ***
```

The DROP named a table that does not exist and the injected statement was a
`SELECT`. **Nothing real was touched** — but the acceptance is the whole finding:
`Z_a;DROP/**/TABLE/**/tbluser;--` would have run.

### REACH CHAIN (R20)

| Layer | Finding |
|---|---|
| **Route** | `routes/web.php:190` — `DELETE /custom-module/table-delete/{id}` |
| **Group** | `Route::group(['prefix' => 'custom-module'], ...)` — **prefix only** |
| **Middleware** | **`web` only** — session and CSRF. **NO `auth`** |
| **Method** | `tableDelete():214` — **no authentication check, no tenant check, no user check.** It takes `$id`, finds the row, and executes |

**Stated precisely, not maximally:** CSRF means a pure outsider needs a token from
a page first, which is a real barrier. **But there is no authentication and no
tenant scoping**, so any session that can obtain a CSRF token can delete **any
tenant's** module and drop whatever its stored name expands to.

### FIXED — both locks, per the G-SEC-15 principle

1. **At the door** — `'regex:/^[A-Za-z0-9_ ]+$/'` and `max:60` on `table_name`.
2. **At the execution site** — `safeTableName()` returns null unless the stored
   value matches `^[A-Za-z0-9_]+$`, and the caller **skips the statement** rather
   than running it. For rows written before the validation existed.
3. **`SHOW TABLES LIKE` at `:27`** — the same value, the same exposure — is now
   **bound** rather than interpolated.

**Verified:**

| Stored name | Result |
|---|---|
| `Z_customers` | allowed |
| `Z_probe;SELECT/**/1` | **REFUSED — statement skipped** |
| `Z_a;DROP/**/TABLE/**/tbluser;--` | **REFUSED** |

**Existing data checked before the fix: 1 row, and it matches the whitelist** — so
nothing legitimate is broken and no stored payload is sitting in the table.

### STILL OPEN — deliberately not fixed in this pass

**`tableDelete` has no tenant scoping and no auth check.** The injection is
closed; **the missing authorisation is not**. It needs the same treatment as the
other identity findings and is queued rather than bundled into an injection fix.

---

# G-SEC-19 — THE INJECTION SWEEP · **S1**

**A class this phase had never asked about.** Every prior finding was identity or
scoping. The question here is distinct, under G-SEC-18's discipline:

> ### Does any request-supplied value reach raw SQL, a `raw()` call, a `whereRaw`, an `orderBy` on a request field, or a concatenated query string?

**No count is quoted that has not been hand-verified.**

## PROVEN — payload changed the result

| Site | Evidence |
|---|---|
| `lmsmappingController::getData` / `getDataPre` | `chapter_id=1` → **0 rows**; `chapter_id=1' OR '1'='1` → **10 rows**. **FIXED**, payload now returns 0 |

## CONFIRMED BY READ — request value reaches raw SQL, no payload fired

Stated as a weaker claim than "proven", deliberately.

| Site | Path | Status |
|---|---|---|
| `lms/courseController:112-113 → :137` | `$grade` / `$standard` from `$request->input()`, concatenated into `DB::select` | **FIXED** — `?` placeholders + `$bindings` |
| `lms/counselling/counsellingExamController:345 → :399` | `$online_exam_id` from `$request->get()`, concatenated into `DB::select`. (`$user_id` came from the session) | **FIXED** — `?` placeholders + bindings array |

## RULED OUT — ORDER BY and LIMIT, tested rather than assumed

Included because they are *"commonly missed as they do not look like a WHERE
clause"*. **They are not injectable here**, and the test says why:

| Payload into `orderBy($col)` | Rendered SQL |
|---|---|
| `id` | ``order by `id` asc`` |
| `id, (SELECT 1)` | ``order by `id, (SELECT 1)` asc`` |
| ``id`; DROP TABLE x; --`` | ``order by `id``; DROP TABLE x; --` asc`` — **the backtick is DOUBLED, no break-out** |
| direction `asc; DROP TABLE x` | **REJECTED** — *"Order direction must be asc or desc"* |

**Laravel wraps the column as an identifier and escapes internal backticks, and
validates the direction.** `AJAXController:377` passes `$request->sort_order`
straight in, and the worst outcome is a SQL error on a non-existent column — a
robustness issue, **not injection**.

> ## CLASS CLOSED, WITH EVIDENCE — NOT LEFT UNEXAMINED
>
> **This leg produces ZERO findings, not a list of candidates.** Reporting the ~14
> `orderBy($var)` sites as an injection count would have been a fabricated number.

`whereRaw` / `orderByRaw` / `havingRaw` / `selectRaw` carrying a request value:
**none found.**

> **Do not re-open the ORDER BY / LIMIT leg on a pattern match.** A grep returns
> ~14 `orderBy($var)` sites; the builder's own output shows every one of them is
> wrapped and escaped. **The class is closed by test**, the same standing as any
> proven negative — and reporting those 14 would have been a fabricated count of a
> vulnerability that does not exist here.

## STILL OPEN — named, not silently dropped

- `CustomModuleController:225` — `DB::statement('DROP TABLE IF EXISTS ' . $table->table_name)`. The value comes from the **database**, not the request, so it is not directly injectable — but **whether `table_name` is user-controlled at creation time is unexamined.** A stored-then-executed path.
- POST/PUT bodies were not swept; this pass covered controller source, so the coverage is by code, not by verb — but the `{id}` and write-verb probe remains the way to exercise them.

---

# G-SEC-19 — SQL INJECTION IN `lmsmappingController` · **S1** · **FIXED**

**Found by running the fail-closed verification, not by looking for injection.**

`getData` and `getDataPre` both build `$extra` by **string concatenation** and
splice it into raw SQL:

```php
$extra .= " AND chapter_id = '".$chapter_id."'";   // $chapter_id = $request->get('chapter_id')
...
$data = Db::select('SELECT * FROM lms_mapping_type AS a WHERE a.parent_id=0 '.$extra.'
    UNION SELECT * FROM lms_mapping_type AS b WHERE b.parent_id != 0 '.$extra);
```

**The request value reaches the database as SYNTAX, not as data.**

### Proven behaviourally

| `chapter_id` | rows |
|---|---:|
| absent | 10 |
| `1` (honest, no match) | **0** |
| `1' OR '1'='1` | **10** |

The payload changed the result. That is the whole proof.

### Fixed — bound, both methods

`?` placeholders with a `$bindings` array, passed as `Db::select($sql, array_merge($bindings, $bindings))`
(the UNION repeats `$extra`, so the bindings are supplied twice, in order).
`AND globally = '1'` stays a literal — no request input is involved.

**After the fix:** absent → 10, `1` → 0, payload → **0**. The payload now behaves
exactly like an honest non-matching value, and the legitimate paths are unchanged.

---

# THE FAIL-CLOSED VERIFICATION — WHAT IT ACTUALLY FOUND

Asked for as a one-line-each confirmation. It found **two defects and one
correction**, which is why it was worth asking for.

### 1. A bug in my own fix — `session()` throws, it does not return null

```php
$request->session()?->get('sub_institute_id')   // WRONG
```

`$request->session()` raises **"Session store not set on request"** when there is
none, so the null-safe operator never gets the chance to help. **An API call with
an unusable token would have died with a 500 instead of failing closed.**

Corrected to `$request->hasSession() ? $request->session()->get(...) : null` in all
six controllers.

> **`null` failing closed was the right instinct; `null` never arrived.** The
> requirement *"not an error, and not a query with the clause silently dropped"*
> is exactly what caught it.

### 2. The routes are behind auth middleware — so 401 proves the route, not the resolver

An anonymous HTTP request returns **401** and never reaches the fixed code. The
property had to be exercised **at the method**, with a Request carrying no token
and no session. **A green HTTP probe here would have proved nothing.**

### 3. Correction — `lms_mapping_type` is a GLOBAL reference table

The verification reported *"10 rows returned, query has no tenant clause"* and I
first read that as a leak. **It is not.** `lms_mapping_type` has **no
`sub_institute_id` column** (56 rows, global). **No tenant clause is correct
there**, and my check counted `final_data` — which comes from that global table —
rather than the tenant-scoped `chapter_topic_data`.

**Confirmed fail-closed:** the two tenant-scoped queries
(`chapter_master`, `topic_master`) both filter on `sub_institute_id` and return
empty when it is null.

---

# CORRECTION — `getDataPre` IS FLAG-GATED

I reported it as the worst instance because it *"needs nothing — no flag, no
omission"*, and proposed the framing that it is **currently wrong for every
caller**. **That was wrong, and I am correcting it before it is recorded.**

`lmsmappingController::index:51-52` calls `getDataPre($request)` **only when
`$request->has('preload_lms')`**. The literal assignment is unconditional *inside*
the method; the method is not unconditionally reached.

**So it is form 3 like the others**, not a fourth and worse form. No customer has
been silently reading tenant 1's content on every request; they would have to hit
a `preload_lms` URL.

> **This is the same error as G-COMP-SEC-01, one level down: I read the method and
> not its caller.** There the controller's code was not the endpoint's behaviour;
> here the method's code was not the method's reachability. **Same shape, second
> occurrence — the boundary of what you read is the boundary of what you know.**

**The downstream-consumer check was still run** and is what produced the
correction: the only caller is `index()`, and one other controller
(`lms_lessonplanController:29`) uses `preload_lms` **correctly**, resolving the
tenant from the session — so it was never in the affected set.

---

# G-SEC-18 — **A REQUEST FIELD SWITCHING THE IDENTITY MODEL** · **S1** · the named pattern

Three variants of **one idea**, each found separately, each treated as its own
incident until now. **They are one pattern, and the sweep should look for the
pattern — not run three greps.**

> **The caller supplies a field, and the server changes WHO IT THINKS THEY ARE —
> or whether it asks at all.**

### The three known forms

| # | Form | Instance | What the field does |
|---|---|---|---|
| 1 | **Switches the CHECK off** | `if ($type == "API") { …verify token… }` | Omit `type` → **authentication does not run** (G-SEC-13: 46 controllers, 132 unguarded routes) |
| 2 | **Switches the TENANT by default** | `$sub_institute_id = $request->sub_institute_id ?? 2` | Omit the field → **become tenant 2** (G-SEC-15; `AnalyzeJDController:28` → tenant 3) |
| 3 | **Switches the IDENTITY by flag** | `if ($request->has('preload_lms')) { $sub_institute_id = 1; $user_profile_name = 1; }` | Set the flag → **become tenant 1 with an elevated role** (G-SEC-17) |

Form 3 is not a fallback at all. It is *"if the caller sets a flag, become tenant 1
with an elevated role"* — **the identity model itself is selected by the request.**

### `lmsmappingController::getDataPre` — CORRECTED, see the correction above

The assignment is unconditional **inside** the method, but the method is reached
**only** via `preload_lms` (`index:51-52`). **It is form 3, not a worse fourth
form.** My original framing — *"needs nothing, wrong for every caller"* — came
from reading the method without its caller and is withdrawn.

### What the sweep looks for, from now on

**ONE QUESTION, NOT THREE GREPS** — kept verbatim, because three greps each miss
what they were not written for and one question does not:

> ### Does any request-supplied value reach a decision about WHO the caller is, WHAT TENANT they are in, or WHETHER A CHECK RUNS? Tenant literals, role literals, and guard conditions keyed on a request
field are all the same finding.

### FIXED — all nine sites of form 3

`resolvedTenantId()` in the six affected controllers: **token first, session for
the Blade screens that have no token, and `null` when neither identifies the
caller** — so every `where sub_institute_id = ?` matches nothing. Failing closed,
the contract `ResolvesApiIdentity` already documents. `chapterController`'s
hardcoded `$user_profile_name = 1` is resolved from the session the same way.

All six controllers verified to load with the trait resolved.

---

# C23 / C24 — THE THIRD-TENANT BLIND SPOT · recorded against BOTH

**A route pinned to a THIRD tenant is invisible to a two-tenant differential
test.** It returns the same response as tenant A and as tenant B, so it scores
**PASS**. C28's marker scan searches only for tenant-B strings, and tenant 1 is
neither A nor B.

> **Today's verified-green read half has a known shape it cannot detect.**

### Second time the marker set's composition decided what could be seen

| | Limit |
|---|---|
| **C28** | Every tenant is seeded from the same global libraries, so no title was unique — markers had to be hand-picked, and personal names excluded |
| **G-SEC-17** | A route pinned to tenant 1 produces no difference for a comparison set of {A, B} to express |

**Both times the limit was in WHAT WAS COMPARED, not in how.**

> ## A differential test can only see differences its comparison set can express.

### The fix needs both legs — neither covers it alone

**(a) a third-tenant marker pass** — necessary, but **it generalises badly**: a
route pinned to tenant 5 would still pass.

**(b) the STATIC check for tenant and role literals in resolution paths**, which
G-SEC-17 has now produced and which is bounded by the code rather than by the
sample of tenants.

**(b) folds into C23's suite as a standing check — it runs WITH the guard, not
beside it.** A static check kept in a separate script is a check that stops being
run.

### Before the write half is built

**Confirm the write half does not inherit the two-tenant design.** If it does, it
inherits this blind spot, and discovering that after building 772 routes' worth of
coverage would be expensive. **Registered as a precondition on the write half, not
a follow-up.**

---

# G-SEC-17 — HARDCODED TENANT LITERALS IN RESOLUTION PATHS · **S1**

The `?? 2` in `getSkillCompetency` was **the second instance of a signature**, not
an incident: `AnalyzeJDController:28` falls back to tenant **3**, `getSkillCompetency`
defaulted to tenant **2**. **Both were found by accident, neither by looking.**

Swept deliberately. **Nine sites across seven LMS controllers assign a tenant
literal UNCONDITIONALLY** — not as a fallback:

| File | Line |
|---|---|
| `lms/chapterController` | 113 |
| `lms/contentController` | 35, 535 |
| `lms/flashcard/flashcardController` | 37, 119 |
| `lms/lmsmappingController` | 94, 155 |
| `lms/teacher_resource/lms_teacherResourceController` | 52 |
| `lms/topicController` | 35 |

```php
// chapterController::getData
if ($request->has('preload_lms')) {
    $sub_institute_id = 1;          // <- not a fallback. An assignment.
    ...
    $user_profile_name = 1;         // <- and the role, too
}

// lmsmappingController::getDataPre
$sub_institute_id = 1;              // <- unconditional
```

**Any caller who sets `preload_lms` reads tenant 1's LMS content**, whatever tenant
they belong to. The role is hardcoded alongside it.

### Why C23 could never have found this

**C23's proxy is differential:** call as tenant A, call as tenant B, compare. A
route hardcoded to tenant 1 returns **the same response both times** — so it scores
**PASS**. C28's marker scan only searches for **tenant B** strings, and tenant 1 is
neither A nor B.

> **A route pinned to a THIRD tenant is invisible to a two-tenant differential
> test.** This is a structural blind spot in the guard, not a bug in it — and it
> is the second time the marker set's composition has decided what could be seen.
>
> **C24's read half needs a third-tenant marker pass before it can be called
> complete.** Recorded against the gate.

---

# G-ATT-SEC-01 — PUNCH IN OR OUT AS A COLLEAGUE · **S1** · **FIXED**

### Route file first, as required

`routes/api.php:586-590`. The group carries **NO middleware**, and the route file's
own comment declares the intent: *"Self service - my attendance calendar and
punches"*. **Nothing at the route layer supplied the missing check** — so the
controller reading `$request->input('employee')` was the whole of the control.

`'employee' => 'required'` validated that the parameter was **present**, never that
it was **the caller**.

### Fixed

`punchSubject()` resolves the subject to `$context['user_id']` — token-derived —
across all three sites (`punchIn`, `punchOut`, and the punch-out fallback lookup at
`:353`, which the first pass missed and a follow-up grep caught).

A mismatched `employee` is **refused, not silently ignored**: rewriting it would
hide a client bug and leave the audit trail disagreeing with what the client
believed it sent.

**Verified, one request per process (G-HARNESS-01):**

| Request | Result |
|---|---|
| caller 2, `employee=3` (a colleague) | **403** — *"You may only record your own attendance."* |
| caller 2, `employee=2` (self) | **200** — punch saved |

**Test data removed** (R8: the row was read before deleting — id 994,
`ipaddress_in=127.0.0.1`, today, no punch-out; unmistakably the probe's). **This is
a shared remote database, so the cleanup is not optional.**

### WHICH ROWS COME BACK — asked separately, and it is clean here

`myAttendance` filters on `$context['user_id']` (`:44`, `:86`) — **token-derived,
caller-scoped**. Unlike `assignmentController`, the row-scope half of this screen
needs nothing.

**Menu 100 is therefore a re-grant candidate; menu 101 (Attendance Reports) is
NOT** — it is a different controller and has not been assessed.

---

---

# G-LEAVE-SEC-01 — **FIXED**, and it was a WRITE, not only a read

### REACH CHAIN — established before reporting, per the boundary rule

| Layer | Finding |
|---|---|
| **Route** | `routes/api.php:521` — `Route::prefix('leave')->group(...)` |
| **Middleware** | **NONE on the group.** The controller's own context guard is the entire control |
| **Callers** | `LeaveRequestApiController::store():146` and `LeaveOptionsController::balances():96` |

**Nothing upstream supplied the missing check.**

### Correction to my own severity framing

I described this as *"passing `employee_id` returns a colleague's leave"* — a read.
**Line 174 is inside `store()`.** So an employee could **file a leave request AS a
colleague**: a write, attributed to someone else, entering an approval workflow.
`balances():96` is the read half.

### Fixed

`leaveSubject()` in `ResolvesLeaveContext`, the same shape as
`competencySubject()`: the subject must be the caller, or the caller must hold an
elevated `role_key`, and the subject must be in the caller's own tenant (checked
first, so a cross-tenant id cannot be probed for existence).

`department_head` and `reporting_manager` are **absent** from the elevated set for
the same reason as in competency — their scope is *my department* / *my team*, and
neither is evaluable while `reporting_manager_id` is NULL for every user
(**G-ORG-02**). They return with reporting coverage, not with a fix.

**Verified, one request per process:**

| Request | Result |
|---|---|
| `employee_id=3` (a colleague) | **403** — *"You may only act on your own leave."* |
| `employee_id=2` (self) | **200** |
| no `employee_id` at all | **200** — the honest path is unchanged |

### WHICH ROWS COME BACK — asked separately, and it is NOT clean

Two things the identity fix does **not** address, both still open:

| Site | Problem |
|---|---|
| `LeaveRequestApiController::show():98` | Filters `hel.id = $id` and the tenant, **no caller check** — any employee reads any colleague's leave request by id. **This is an `{id}` route, i.e. exactly the half the probe has not reached** |
| `LeaveRequestApiController::index():40` | No caller filter — appears tenant-wide |

**Menus 102/103/104 stay DENIED.** The identity defect is closed; the row-scope
question is not, and a route guard would not answer it either.

---

---

# G-TALENT-01 — TALENT HAS NO SELF-SERVICE SCOPING AT ALL · **BUILD ITEM, NOT A PATCH**

**Zero caller-scoped queries across all 11 Talent controllers.** Not a leak to
patch — **a module where the self-service concept was never built.**

§3.x grants Employee five Talent screens on qualifiers — *referrals · own
checklist · self-appraisal · internal jobs · own exit* — and **none of those flows
exist in enforceable form.**

**Filed against §3.8 and the Talent connection items**, not against the security
queue. Patching a boundary that was never drawn is not a fix; the flows have to
be built.

---

# G-RBAC-01 — 121 GRANTS CARRY A QUALIFIER THE TABLE CANNOT EXPRESS · **S2**

**Measured 2026-08-10, not asserted.** Of the grants in `03-rbac-matrix.md`
§3.1–3.7, **121 carry a parenthetical qualifier that is doing the real work** —
and `tblgroupwise_rights_g2g` **cannot express any of it.**

| Role | Qualified grants |
|---|---:|
| Department Head | **36** |
| Reporting Manager | **35** |
| **Employee** | **31** |
| HR Executive | **19** |
| **Total** | **121** |

The table holds **one boolean per action per menu**. No row scope. No field scope.
So `V (own payslip)`, `V (team)`, `V (dept)` and `V (org — basic fields)` all
flatten to the same thing: **full access to the screen**.

**This is exactly what §3.8's field-level layer exists for**, and its scope is now
**measured rather than asserted**.

### The two kinds, and they resolve differently

| Kind | Examples | Resolution |
|---|---|---|
| **"OWN X"** | own payslip · own checklist · self · own exit · referrals | **Read the controller.** If it already scopes by the caller's `user_id`, the qualifier describes behaviour that exists and a bare `V` is safe. **Record the file:line proving it** |
| **"TEAM / DEPT X"** | team · dept · own dept | **DENY for now.** Enforcement needs `reporting_manager_id`, which exists but is **NULL for all 387 users**. Registered against the reporting-coverage readiness gate — they turn on when coverage does |

> **Where the controller does not scope, DENY.** A missing menu is a support
> ticket; an over-grant on compensation is a security finding. Same asymmetry as
> additive-vs-subtractive.

---

# G-RBAC-02 — SPEC-ASPIRATION MASQUERADING AS A PERMISSION DECISION · **S2**

**§3.1–3.7 was written from role-wise feature specs describing what the product
SHOULD do.** Where a qualifier refers to a capability that was **never built**,
there is nothing to enforce the qualifier on — **and the safe reading is always
DENY.**

### The proven instance

**`Payroll (all 7 screens)` → Employee `V (own payslip)`.**

**There is no payslip screen among the seven.** The seven are Payroll Type, Salary
Structure, Rollover Salary Structure (disabled), Payroll Deduction, Form 16, Salary
Certificate and Monthly Payroll Report — **four configuration screens, two personal
documents that take `employee_id` from the request, and one org-wide report.**

Granting the bare `V` would have shown **238 employees the organisation's salary
structure and payroll deductions** on the day the permissions fix shipped.

### CONFIRMED AS A PATTERN, NOT AN ANECDOTE

**Two independent cases, different modules, different evidence:**

| Case | Evidence |
|---|---|
| **Payroll** `V (own payslip)` | No payslip screen exists among the seven |
| **Skill Gap Analysis** `V (self)` | `status=0`, **no component and no nav entry** in the Next.js app |

> **§3.1–3.7 CANNOT be read as a description of current behaviour.**
> It is a specification of *intended* behaviour. Every qualifier in it must be
> tested against the controller before it becomes a grant.

**This is now load-bearing in the §3.8 scoping argument.**

### Flagged as likely siblings

- **Skill Gap Analysis** `V (self)` — marked (SHIP), but the menu is `status=0` with no component behind it (**G-A-04**). A qualifier on a capability that does not exist.
- **Every other §3.x grant whose qualifier implies a capability that cannot be located** — to be flagged as each batch is read.

**These are not permission decisions. They are aspirations, and they must not be
seeded as grants.**

---

# G-DUP-01 — TWO PARALLEL RIGHTS SYSTEMS FOR ONE CONCEPT · **S2**

**One live with real data, one placeholder for a newer sidebar.** Same duplication
pattern this phase exists to remove, and **the plan must decide it explicitly
rather than inherit it.**

| | Blade | Next.js |
|---|---|---|
| Menu tree | `tblmenumaster` (200) | `tblmenumaster_g2g` (188) |
| Rights | `tblgroupwise_rights` (1,254) | `tblgroupwise_rights_g2g` (4,879) |
| Reader | `MenuMiddleware` | `tblmenumasterG2gController::displaySidebarMenu` |
| Individual rights | `tblindividual_rights` (0 rows) | *(shared)* |

**Menu trees overlap but do not match: 170 ids in both · 30 legacy-only · 18
g2g-only.**

Reconciliation by profile — distinct menus with `can_view=1`:

| Role | Legacy | _g2g |
|---|---:|---:|
| Admin | **200** | 157 |
| HR | **71** | 150 |
| Employee | **169** | 157 |

**Admin loses 43 menus and HR gains 79 if the _g2g seed were taken as-is.** That
diff is the deliverable for the consolidation item, not a side effect of 4b.

**❌ X-01c IS CANCELLED.** *(2026-08-07)* **There is nothing to consolidate: two
products, two tables, each keeps its own.** The Blade UI is out of scope
(G-SCOPE-01), so its rights table is not ours to reconcile, migrate or retire.



---

# ⚠️ WHAT ELSE WAS MEASURED ON THE _g2g TABLES — inheritance check

| Artefact | Table used | Inherits the error? |
|---|---|---|
| `audit-authorization.py` → **G-SEC-04** | `tblgroupwise_rights_g2g` | ⚠️ **Partly.** Its route-to-menu map is against the **Next.js** tree. Correct **for the Phase 3 product**, but it does **not** describe the Blade UI |
| `dump-menu.php`, `nav-crossref.py` → **Gate A inventory**, `01-inventory.md`, `01b-scope-triage.md` | `tblmenumaster_g2g` | ⚠️ **Same.** The 104-row triage and the whole nav inventory describe the **Next.js** sidebar |
| `audit-auth-sweep.py`, `audit-route-controllers.py` | no rights/menu table | ✅ unaffected |
| **G-NAV-01** (menu row 219 fix) | `tblmenumaster_g2g` | ⚠️ fixed the **Next.js** tree only |

**The honest reading: this is not an error, it is a SCOPE that was never stated.**
Phase 3's product is the **Next.js** front-end, so measuring its tree was right.
**But every nav and menu figure in this phase describes the Next.js sidebar and
says nothing about the Blade UI**, and no document said so until now.

**No number needs re-deriving.** They need the qualifier attached.

---

---

# G-SCOPE-01 — THE BLADE UI IS OUT OF SCOPE · ✅ **CLOSED 2026-08-07 by decision**

**Not a documentation gap — an unexamined surface.** Every nav, menu, triage and
route-to-menu figure in this phase describes the **Next.js** sidebar. The Blade UI
has its own menu tree, its own rights table, and **its screens were never in the
audit's scope.**

## The evidence, both directions

| For "live and maintained" | |
|---|---|
| Blade views on disk | **173** |
| Controllers returning `view()` | **21** (46 call sites) |
| **Last change to `resources/views`** | **2026-07-31** |
| Last change to `routes/api.php`, for comparison | 2026-08-03 |
| Its own middleware stack | `MenuMiddleware` + `authMiddleware`, actively used |
| Its rights table | **1,254 rows, genuinely differentiated** — someone configured this |

| For "legacy on the way out" | |
|---|---|
| — | **No evidence found.** No deprecation notice, no redirect-to-Next.js, no removal commits |

**Verdict on the evidence: the Blade UI is LIVE and was touched three days before
the API surface.** Nothing suggests retirement.

## What was and was not covered — the precision matters

| | Covered? |
|---|---|
| **Blade ROUTES, tenant isolation** | ✅ **YES.** The C23 guard ran across **all six route files**, including `web.php`, `hrms.php`, `lms.php`, `settings.php`, `user.php`. Blade's routes are inside the 46 failures and the 66 token-reachable controllers |
| Blade **screens, menus, rights** | ❌ **NO.** Gate C's six write-ups audited the Next.js screens only |
| Blade **menu tree** (`tblmenumaster`, 200 rows, **30 not in `_g2g`**) | ❌ **NO** |
| Blade **rights** (`tblgroupwise_rights`, 1,254 rows) | ❌ **NO** |

**So the security hole is narrower than "unaudited surface" suggests** — tenant
isolation *was* tested there, because `authMiddleware` accepts a token and the
guard walked every route file. **What is unexamined is its features, menus and
rights.**

## Recommendation

**Treat it as LEGACY, DEFERRED — and say so explicitly, rather than leaving it
undeclared.**

Reasoning:

1. **Phase 3's product is the Next.js front-end.** Every golden thread, every module write-up and the whole connection plan target it. Auditing Blade now would restart Gate C for a UI the plan does not build on.
2. **Its routes are already covered for the one risk that matters most** — tenant isolation, via C23.
3. **The 30 legacy-only menus go with it.** They are screens the Next.js product does not have and, on the evidence of the triage, does not intend to.
4. **But it is live**, so "deferred" must mean *declared and dated*, not *forgotten* — with a stated intent to retire it, and X-01c's consolidation as the first step.

⚠️ **If Triz says it is staying**, then it needs its own Gate C pass and its rights
table needs the same curation as `_g2g` — and X-01c cannot retire either table.

## ✅ DECIDED 2026-08-07 — OUT OF SCOPE

> **Phase 3 continues with the NEW G2G INTERFACE (Next.js) only. The Blade UI is
> NOT the product being built.**

**Not "deferred with intent to retire" — simply not the product.** If it later
needs work, that is its own phase.

| | |
|---|---|
| The 30 legacy-only menus and `tblgroupwise_rights` (1,254 rows) | **belong to it** |
| Audit it? | **No** |
| Curate its rights? | **No** |
| Retire or modify its tables? | **No. Leave it entirely alone** |
| The scope qualifier on every nav/menu/triage/route-to-menu figure | **now correct BY DEFINITION rather than by accident** |

**⚠️ ONE EXCEPTION STANDS: Blade routes remain inside the C23 tenant-isolation
scope**, because `authMiddleware` accepts a token and they are reachable.
**Security coverage does not narrow with product scope.**

---

## Data provenance — read before any row-count conclusion

**M3.** Several findings rest on row counts (99% overdue, 1 progress row, 0
certificates, 169 capability ratings). Their meaning depends entirely on what this
database *is*.

### Verdict — OWNER-STATED, 2026-08-05

> **This database contains TEST DATA ONLY. There is no production tenant and no
> real customer.** The 4,518 tokens across 299 days are the owner's own team
> working on the product during development.

### The tag to apply

> **[PROVENANCE: test data — no production tenant, stated by owner]**
> Row-count findings mean **"never exercised"**. No customer is affected by any
> defect found so far, and nothing needs a separate urgent timeline.

### Methodological note — worth more than the finding

My first answer to this question was **wrong**, and wrong in an instructive way. I
asked the right question ("is this real usage or seed data?") and then answered it
**by inference from the same data** — reading 299 distinct login days as evidence
of organic customer use, when it was the development team.

The inference was reasonable and the conclusion was false. Provenance is not a
property of the data; it is a fact about the world that only the owner holds.

**Rule adopted:** *for any question of the form "what does this data represent?",
ask the owner and wait. Do not infer provenance from the artefact whose provenance
is in question.*

---

## The standing rules

All four came from the same failure mode: **a self-consistent method producing a
confident wrong answer.** None is caught by checking harder within the same
evidence.

| # | Rule | Came from |
|---|---|---|
| **R1** | **No number from an audit script is quoted until cross-checked by a SECOND independent method** — a different parser, a manual count of a sample, or a query from the other side | `G-QUAL-02` — a regex silently dropped 52 routes; the authorization audit under-reported |
| **R2** | **Provenance is asked, never inferred.** For "what does this data represent?", ask the owner and wait | M3 — 299 login days read as customer usage; it was the dev team |
| **R3** | **Before quoting any count derived from parsing, look at FIVE ACTUAL ROWS by eye.** **Extension:** *"this is test data"* is a fact about the world, **not a statement about any particular table's value** — curated reference libraries live happily in a test database. Disposability must be measured, never inferred from provenance | S1 — "9,400 capability measurements" were 293 real items and 9,105 characters of a double-encoded string. Extension — the throwaway hypothesis for L-15/16/17/19, which the row counts refuted |
| **R15** | **ASSERT THE COLUMN EXISTS BEFORE QUERYING ITS VALUES**, in the same script, failing loudly if it does not | C33 — *"column absent"* and *"value NULL"* returned the **same result** and were indistinguishable. The query measured *"did I get null"*, not *"is this row global"* — **C22's proxy defect at query level**. Right conclusion here; on a table that **does** have the column, the same shape would have read a tenant-owned row as global, wrong in the reassuring direction (R11) |
| **R16** | **EVERY SWEEP NAMES A KNOWN-POSITIVE UP FRONT AND MUST DETECT IT BEFORE ANY OUTPUT IS QUOTED** | S-5 was built to rediscover the Command Center divergent-vocabulary defect **and missed it**, so its clean result meant nothing. Same principle as C29: the C23 guard was not trusted until it watched a route go FAIL → PASS. **Origin cases: S-5** (built to rediscover the Command Center divergent-vocabulary defect, missed it) **and S-2** (rewrite failed its gate; **111 endpoints withheld**, with the reason visible in the output itself — `per_page`, `last_page` and `range_label` are **response** fields, not payload fields. Without the gate that number enters a document and takes a long time to unpick) |
| **R12** | **Every turn lands at least one queued item.** A turn may not be new investigation plus a question. If a discovery consumes the turn, the queued item still ships | Tokens (3 turns) and sweeps S-1/S-2/S-5 (5 turns) were repeatedly displaced. From the owner's side that is indistinguishable from them not happening |
| **R13** | **Standing authority — decide and report, do not ask:** minting tokens / test rows in the named tenant (registered in `11-verification-protocol.md` §6) · any read-only investigation · reclassifying my own estimates · adding register candidates · ordering work within an agreed queue · correcting prior statements. **Still ask:** destructive or bulk writes, deletions, schema changes, user-facing workflow changes, and any collision between instruction and evidence | Three turns ended on "your call" where unblocked work existed |
| **R14** | **Turns end at boundaries, not at questions.** Ask the question AND continue whatever it does not block | as above |
| **R18b** | **Anything merged verbatim from a recovery carries a DATE STAMP.** A stale line looks identical to a current one | *"2 of 32 delivered"* came back in a recovered Gate checklist and **survived three write-ups unnoticed**, contradicting a line 80 rows above it |
| **R19** | **A NUMBER ASSEMBLED FROM OTHER NUMBERS IS A NEW CLAIM.** Re-derive it end to end, with one filter, before publishing. **Verifying each input separately does not verify their combination** | **V6.** Three of four headline errors came from combining figures derived at different moments with different filters — and all three **overstated**, flipping R11's direction. Under-reporting hid risk; over-reporting costs credibility |
| **R19b** | **AN AGGREGATE IS NOT AN EXPERIENCE.** A total summed across tenants, users or profiles must never be quoted as if it described one of them. State the per-unit figure, or say explicitly that the number is an aggregate | **G-SEC-07.** *"Employee sees 1,657 menus vs Admin 1,500"* was summed across **11 tenants**. What **one user** sees is **151 vs 136**. Same family as R19: a figure assembled from parts and then read as something it never measured. **The inversion survives and remains the finding** |
| **R17** | **(a) Before writing any new measurement script, check whether an existing artefact already answers the question — reuse it. (b) When a new measurement disagrees with an old one, resolve the discrepancy BEFORE either is used** — R4b applied to my own artefacts, not just to the codebase | **C38.** The tooling was right and the correct number was already in `c21-result.json`. I wrote a fresh, narrower grep, got 17 against its 30, and acted on mine without comparing |
| **R10c** | **INTENT IS NOT BEHAVIOUR.** A comment, a `$description`, a commit message or a design note explains what someone MEANT. Only the running code says what HAPPENS. Never let the first stand for the second | **G-SEC-07, second correction.** `SeedG2gDefaultViewRights`' description says the uniform `can_view=1` is a placeholder *"until an admin curates real rights"* — **true about intent, and it was never replaced.** The product ships on the placeholder. **The same proxy error this project has now caught six times**, one level up: not a checker measuring a proxy, but a *document* standing in for a behaviour |
| **R10b** | **A PROXY THAT NAMES ONE COLUMN MEASURES ONE COLUMN, NOT THE CLASS.** Before writing down any scope figure, ask: **"what else does this pattern look like under a different name?"** Where the answer is unknown, mark it **ESTIMATE PENDING** rather than guess | **G-SEC-12.** S-3 counted `created_by` and reported **33**. The real class spans `created_by`, `updated_by`, `verified_by` and `reviewer_id` — **one pattern under four names — and the true figure was 76. Low by 2.3×.** ESTIMATE PENDING was correct and should be used the same way again |
| **R11** | **A SCOPE-SHRINKING ASSUMPTION IS VERIFIED BEFORE IT IS USED TO SHRINK ANYTHING.** State it, test it, then apply it — never the reverse | **Four consecutive errors all ran in the reassuring direction.** Random tooling error would split evenly between overstating and understating; these did not. The cause is structural: **an assumption that shrinks the work is adopted more readily than one that expands it, and the result looks tidier, so nobody questions it.** This is the only rule that would have caught all four in advance |
| **R10** | **EVERY CHECKER TESTS A PROXY — NAME IT.** Any document quoting a script's number must state (a) the property we care about, (b) the proxy actually measured, (c) how something passes the proxy and fails the property **and vice versa** | **C22** — Phase 1's sweep used *"calls `findToken()`"* as a proxy for *"resolves identity correctly"*. A controller that validates a token and discards its owner satisfies that proxy **perfectly**. That is how G-SEC-09 survived a phase dedicated to finding it |
| **R4** | **When a checker disagrees with the artefact it is checking, THE CHECKER IS THE PRIMARY SUSPECT.** Investigate the tool before reporting the artefact as wrong. A failure list is a hypothesis, never a result | Calibration C1/C1b, the Q-L1 sweep, and sweep S-4b — **eight disagreements, eight times the tool was wrong, zero times the codebase was** |

R3 is the cheapest and would have caught the most. The S1 result is why it exists:
migrating blind would have **manufactured 9,105 fake capability measurements about
real people**. Four separate counts in this phase were self-consistent and wrong,
and every one would have been caught by opening five rows.

**THE ASYMMETRY THAT MATTERS.** Cases 9 and 10 both **understated** the risk, and
that is the more dangerous direction. **A checker that under-reports produces false
confidence** — and that is exactly how `skillLibraryController` survived Phase 1: a
sweep that counted `findToken()` as a guard reported it as protected. An
over-reporting checker wastes an afternoon; an under-reporting one closes a phase
with a tenant-boundary breach still open.

R4 is the newest and the most uncomfortable, because every one of its first five cases
failed in the *reassuring* direction for the tool and the *accusing* direction for
the codebase — a checker that reports a 15% error rate feels like a checker doing
its job. The tally, in order:

| # | Reported | Real | The checker's actual bug |
|---:|---:|---:|---|
| 1 | 24 of 159 (15.1%) | 0 | Literal URI matching — a concrete segment never matched a `{param}` route |
| 2 | 3 of 159 (1.9%) | 0 | Counted an elided `...` prefix as a path segment |
| 3 | 10 of 206 (4.9%) | 0 | Could not read compound `api` notation (`"POST/PUT due_date"` is a payload field) |
| 4 | 6 of 206 (2.9%) | 0 | Applied `"POST or PUT /x"` shorthand's PUT to the collection instead of the member URI |
| 5 | 1 of 206 (0.5%) | 0 | Frontend-only file index, so a legitimately backend-anchored row was "unparseable" |
| 9 | C15: *"0 hits in trait-using controllers, so all 64 are legacy"* | — | "No trait" ≠ "not an API". `skillLibraryController` defines its **own** context method and has 12 API routes |
| 10 | C15: *"`jobroleLibrary1Controller` is unrouted"* | — | Grepped 2 route files. **There are seven.** Three controllers called unrouted are routed in `lms.php` / `settings.php` |
| 13 | PayrollController: *"18 sites in three resolution styles"* | **26 sites in FOUR** · **C38: the ENUMERATION was fine; my ad-hoc grep was not.** `c21-tenant-enum.py`'s regex has an empty alternative `(?:input\(|get\(|header\(|)`, so it **does** match `$request->sub_institute_id` — verified on all four shapes. It reported **30 hits** for PayrollController. I then wrote a narrower one-off grep for fix planning, got **17**, and acted on that. **The right number was already in an artefact I had produced. R1 — cross-check with a second method — would have caught it, and I never compared the two.** So the 66-controller scope, the 460 hits and the C27 list are **NOT understated**, and no controller was cleared by a blind instrument | `$request->sub_institute_id` — **magic property access** — matches neither `->get()` nor `->input()`. 9 sites invisible, two of them **overwriting a correctly-resolved value two lines later**. Caught only because the guard stayed red |
| 12 | C28: **4 `LEAK-NOSCOPE` routes** | **0** | Markers were selected by excluding tenant 7 only — never the other tenants, never the **global** `s_jobrole` / `master_skills` libraries. Routes serving the global catalogue tripped them. **First case to OVER-report**; the other eleven all understated |
| 11 | *"Blade route files are session-authenticated, so the tenant is already fixed"* | **FALSE** | `authMiddleware` accepts **a session OR a bare Sanctum token**. Four of six route files are token-reachable. Scope went **35 → 66 controllers**. Fourth consecutive error in the reassuring direction |
| 6 | 24 columns swept, **25 rows to explain** | — | Swept `business_link` once when there are **two** columns: `business_link` (KASA) and the misspelt `bussiness_links` (Skill). One field went unchecked |
| 7 | S-4b: 9 dead state gates | **1** | A setter passed **by reference** is a real call site — `onSelect={setSelected}`, `.then(setVerification)` never match `setSelected(` |
| 8 | (same run) | — | **Initial `useState` value ignored.** `useState(true)` starts satisfied; `setLoading(false)` only turns it off |

Had rate 1 been quoted, it would have condemned an accurate sub-module and
triggered a needless re-derivation of ~3,200 rows.

### R4's sixth case, and the narrower rule it earns

Case 6 differs from 1–5: nothing was over-reported. Two counts that should have
matched — 24 columns swept, 25 rows to explain — simply did not, and the gap was a
field nobody had looked at.

Re-checked, `bussiness_links` turned out **not** to be the clean zero the other 23
were. `skillLibraryController.php` and `SchoolSetupController.php:232,264` both
select and copy it. No behavioural consumer, so the conclusion held — but that was
luck, not method.

> **R4b — When two counts that should match do not, resolve the discrepancy before
> using EITHER number.** Not after, and not only if the conclusion looks like it
> might change.

**"Not strictly unread" and "clean zero" are different claims**, and a document
that asserts the second when the first is true has overstated its evidence even
though its recommendation was right. The temptation is to skip the check once the
answer looks unaffected; that is exactly when the check is cheapest and the
overstatement most likely to survive into a customer-facing document.

### R8 — pre-deletion checklist

**A different failure class from R1–R4.** Those catch a *tool* reporting wrongly.
R8 catches a **plan premise** being wrong — and no sweep or audit would have found
it. Only opening the file before deleting it did.

> **Before deleting any file, row or column, produce:**
> 1. **Everything it exports, and everything that references it, with call sites**
> 2. **Which of those have other homes, and where each will move**
> 3. **What becomes orphaned — reported, never silently removed**
> 4. **Whether anything outside the deleted scope changes behaviour**

**Earned by L-03R.** The panel file also exported `dash()`, used at **four live
sites** in the library table. Deleting the file on the strength of *"the panel is
unreachable"* — which was true — **would have broken every library table on all
eight tabs.** The premise was correct and the plan was still wrong.

**Applies to every remaining deletion:** the 65 nav rows, the four `s_skill_matrix`
blob columns, the free-text columns after backfill, and any dead component S-4
finds.

### R9 — after any server-side contract change, re-read every frontend consumer

> **Checklist:** which frontend files call this endpoint · what does each assume
> about the response · **does any optimistic update now assert something the server
> no longer does.**

**Earned by D-002.** The server was changed so restore returns a competency to
`Pending`. An optimistic update in `cm-competency-library.tsx:824` still asserted
`approve_status: 'Approved'` — **the UI would have shown Approved while the
database held Pending.** Static analysis cannot see it; both halves compile. Only
reading the consumer after changing the server finds it.

The same pass caught a second one: the edit payload still **sent** `status`, a
field the server now ignores — the silent-data-loss pattern this audit exists to
remove.

**Highest risk ahead: the rights-matrix population and the event store**, which
change behaviour across many screens at once. A single re-read is not enough there;
each changed endpoint needs its consumers enumerated.

> **Anchor regexes must handle `\r\n`.** Line endings have now defeated a pattern
> **twice** — once on a `$`-anchored trait-use match, once earlier in the phase.
> Costs nothing to guard; prevents the third.

Applied together: R3 before counting, R1 before quoting, R2 before interpreting,
**R4 before accusing, R8 before deleting, R9 after changing a contract.**

### ⭐ The first correction to move in the REASSURING direction

**`G-FLOW-24`, 2026-08-06.** Every prior correction in this phase made a finding
worse. This one made one **better**: `delay_category` is empty because **exactly
one task of 2,271 has ever reached `ON HOLD`** - not because nothing writes it.
`MyTasksController.php:164` validates a closed enum and `:205` writes it.

**It is provenance, not a structural defect.**

> **A register that only ever escalates is being fed, not checked.** Recording the
> one item that got better is how you tell the difference.

**Carried into golden thread 2:** the overdue/stall rule **cannot rely on
`delay_category` being populated**, because the state that fills it has been
reached once. In production it would populate; on this data it proves nothing.

---

### The direction, now beyond doubt

**Thirteen cases. Thirteen under-reports from scope-narrowing assumptions. Zero
over-reports from them.** The single over-report (case 12) came from a rule I had
*tightened*, not loosened.

R11's mechanism is not a hypothesis any more: **an assumption that shrinks the work
is adopted more readily than one that expands it, and the tidier result draws less
scrutiny.** Case 13 is the sharpest instance — a partial fix that would have read as
complete in the source, caught **only because the guard stayed red.** That is
C23-before-fixes demonstrated rather than argued.

### The provenance fact has now saved the project three times

**Recorded because it is the strongest argument for finishing the foundation work
before the first client.**

| # | Finding | What it would have cost with customers | What it costs now |
|---:|---|---|---|
| 1 | **G-DATA-04** — double-encoded blobs | Migrating blind would have manufactured **9,105 fake capability measurements about real people** | Fix the writer, normalise, move on |
| 2 | Breaking schema changes (Correction 2) | Every "for now" compromise becomes permanent | Near-free to undo |
| 3 | **G-SEC-09** — tenant-boundary breach | **Any tenant reads and writes any other tenant's competency library.** Disclosure, contractual breach, and the first thing an enterprise security review tests | A trait adoption |

**There are no customers, so nothing is exposed.** Three times now, "test data, no
clients" has converted a catastrophe into a cheap fix.

**That luck is a depreciating asset.** Each of these was found *because* someone
went looking; the next one may not be. **This is the case for finishing the
foundation before the first tenant is created** — and it is why C24 makes that a
company-level release precondition rather than an engineering preference.

### Consequences applied

| Area | Effect |
|---|---|
| Every row-count finding | Reads as "never exercised". No customer impact. No separate urgent timeline |
| Readiness-gate thresholds (`02-domain-model.md` §8) | **Cannot be calibrated from this data.** Six gates kept; thresholds labelled **UNCALIBRATED DEFAULTS**, to be tuned against the first real tenant |
| Breaking changes | **Nearly free today, expensive forever after.** Drives Correction 2 — every "for now" compromise re-examined |

Tagged conclusions:

| Finding | Number | Correct reading |
|---|---|---|
| `G-FLOW-05` | 0 certificates / 1,426 enrolments | **Never exercised.** Chain verified complete; nobody has completed content |
| `manager.md` §1.1 | 2,245 of 2,271 overdue | **Test data only.** Says nothing about task management as a discipline. Drives the readiness gate (M1), never a product-wide rule |
| `employee.md` §2 | 169 ratings / 386 users | **Never exercised.** The structural chain is the finding, not the sparsity |
| `02-domain-model.md` §8 | all six gate thresholds | **Uncalibrated.** Cannot be tuned against test data |
| `G-FLOW-24` | `delay_category` 0 rows | ⚠️ **CORRECTED 2026-08-06 — it IS provenance.** A write path **does** exist: `MyTasksController.php:164` validates a closed enum and `:205` writes it, gated on `status === 'ON HOLD'`. **Exactly 1 task of 2,271 has ever been ON HOLD.** The column is empty because the state that fills it has been reached once, not because nothing writes it. **The first finding in this phase to move in the reassuring direction** — recorded because a register that only ever escalates is not being checked properly |

### A second trap, avoided

`personal_access_tokens.last_used_at` is **NULL on all 4,518 rows**, which looks
like "nobody ever used a token". It is not evidence of anything: this application
resolves tokens manually via `PersonalAccessToken::findToken()`, and only Laravel's
`auth:sanctum` guard updates that column.

Note the pattern — that trap and the provenance error are both cases of reading
meaning into an artefact that cannot carry it.

**Findings that do NOT depend on provenance** — schema, code paths, route counts,
missing tables, absent join keys — are unaffected. Every S1 in this register is of
that kind.

---

## Counting method — read once, referenced everywhere

Stated in one place per correction **C2**, so every route figure in Phase 3 means
the same thing.

| Term | Definition | Count |
|---|---|---:|
| **API routes** | Uncommented `Route::{get,post,put,patch,delete,any}(...)` declarations in `routes/api.php` | **739** |
| — of which write routes | `POST` / `PUT` / `PATCH` / `DELETE` | **394** |
| `Route::resource` declarations | Each expands to ~7 further routes at runtime | 10 (not counted above) |
| Registered routes, whole app | `php artisan route:list`, all route files, resources expanded | 1,683 |

**Correction applied 2026-08-05.** `03-rbac-matrix.md` previously said 739 and
`07-gap-register.md` said 687. Both were measuring honestly; the parser behind the
687 matched only the short-alias form `[Ctrl::class, 'method']` and silently
dropped **52 routes** written with a fully-qualified inline class
(`[\App\Http\Controllers\...::class, 'method']`) — 11 of them on
`assignment\assignmentController` alone.

That is a defect in the audit tool, not a discrepancy in the writing, and it
under-reported the problem. Fixed in `scripts/audit-authorization.py`; **all
figures below are the corrected ones.** Both documents now say 739.

*Under-counting an authorization audit is worse than not running one — it produces
false assurance. Worth noting the correction came from a reader reconciling two
numbers, which is exactly why the counting method now lives in one place.*

---

## G-SEC-01 — 279 write routes have no authorization check · **S1** · `OPEN`

**The single most commercially serious finding in Phase 3.** This is what
enterprise security reviews test, and it is the kind of finding that stops a
procurement process.

Measured by `scripts/audit-authorization.py` over all **739** API routes. Note this
measures **authorization** ("may this caller do this?"), which is a different and
harder question than the **authentication** ("who is this caller?") that Phase 1
closed.

| | All routes | Write routes |
|---|---:|---:|
| Role-gated (some authorization) | 124 | 111 |
| **Authenticated only — no role check** | **609** | **279** |
| Unknown (method not resolvable) | 6 | 4 |
| **Total** | **739** | **394** |

**In plain terms: 71% of every write endpoint in the product can be called by any
authenticated user, including a plain Employee.** Phase 1 established that the
caller is who they claim and belongs to the tenant they claim. It did not
establish that they are *allowed* to do what they are asking.

Notably, **none of the 52 previously-missed routes were role-gated** — the
role-gated count stayed at 124 while auth-only rose from 558 to 609. Every route
the tool had been blind to was unguarded.

### Highest-risk unguarded write routes, by controller

| Controller | Unguarded writes | Why it matters |
|---|---:|---|
| `Competency\LibraryController` | 19 | Any employee can create/edit/delete the tenant's competency library — the master data the whole product resolves against |
| `Agentic\WorkflowController` | 9 | Agent workflow definitions |
| `Api\LmsLearningController` | 8 | Course content and progress |
| `Offboarding\OffboardingController` | 8 | Exit cases — access revocation implications |
| `Competency\FrameworkController` | 7 | Competency frameworks |
| `Competency\CertificationController` | 7 | Certifications — proof of capability |
| `assignment\assignmentController` | 7 | **Previously invisible to the audit** |
| `Competency\DevelopmentPlanController` | 6 | Development plans, including other people's |
| `libraries\skillLibraryController` | 6 | Skill master data |
| `Onboarding\OnboardingJourneyController` | 6 | Onboarding journeys |
| `Competency\StudioController` | 5 | Framework authoring |
| `Performance\PerformanceActivityController` | 5 | **Performance review activity** |
| `Performance\PerformanceAppraisalController` | 5 | **Appraisals** |
| `Performance\PerformanceCompensationController` | 5 | **Compensation recommendations** |
| `Performance\PerformanceBonusController` | 5 | **Bonus decisions** |
| `Performance\PerformanceCalibrationController` | 5 | **Rating calibration** |
| `Onboarding\OnboardingTaskController` | 5 | Onboarding tasks |
| `Agentic\AgentController` / `Agentic\RunController` | 10 | Agent definitions and runs |

The Performance cluster is the sharpest: **25 unguarded write routes across
appraisal, compensation, bonus and calibration.** An employee who can call
`PerformanceCompensationController` directly can alter compensation
recommendations. That is a finding a buyer's security team will find in an
afternoon.

`CompetencyLibraryController` at 19 is the widest blast radius: it is tenant master
data that frameworks, assessments, courses and tasks all resolve against.

### Why this is not the same as the Phase 1 finding

| Phase 1 (closed) | This (open) |
|---|---|
| Caller identity was taken from the request | Caller identity is now trustworthy |
| One tenant could read another's data | Tenant isolation verified from both sides |
| **Authentication** | **Authorization** |

Phase 1 made "who is calling" reliable. That is the precondition for this fix, not
a substitute for it.

### Fix

Fully specified in `03-rbac-matrix.md` §4 (three enforcement layers) and §5 (9-step
sequencing). Prerequisites: A3's route→menu map (delivered,
`_evidence/route-to-menu-map.csv`) and the role model.

**Sequencing — highest risk first**, to be carried into `08-connection-plan.md`:

| # | Group | Unguarded writes | Why first |
|---:|---|---:|---|
| 1 | Performance (activity, appraisal, compensation, bonus, calibration) | 25 | Money and ratings |
| 2 | Competency master data (library, framework, studio, skill library) | 37 | Blast radius across every module |
| 3 | Certifications + development plans | 13 | Proof of capability; regulated industries |
| 4 | Onboarding / offboarding | 19 | Lifecycle and access-revocation implications |
| 5 | LMS content + assignments | 15 | Course integrity |
| 6 | Leave / holiday | 8 | Entitlement |
| 7 | Agentic | 19 | Powerful, narrower user base |
| 8 | Remaining | ~143 | |

**Evidence:** `_evidence/authorization-coverage.json` (per-route),
`_evidence/route-to-menu-map.csv`. Reproduce with
`python scripts/audit-authorization.py --csv`.

---

## G-SEC-02 — 208 routes map to no menu, so no rights row can govern them · **S1** · `OPEN`

Direct consequence of A3. `tblgroupwise_rights_g2g` is keyed by `menu_id`; a route
with no menu has nothing to check against and will be silently skipped by any
middleware keyed that way — the most dangerous kind of gap, because coverage
metrics will read as complete.

**208** (revised up from 158 after the 739-route correction). Worst:
`Agentic\WorkflowController` 13, `assignment\assignmentController` 11,
`Offboarding\OffboardingController` 11.

**Fix:** declare the mapping explicitly in `routes/api.php`
(`->defaults('menu', '…')`) rather than inferring it. Detail in
`03-rbac-matrix.md` §5A/A3.

---

## G-SEC-04 — The route-to-menu map is not reliable enough to enforce against · **S1** · `OPEN`

> ⚠️ **SCOPE QUALIFIER (2026-08-07):** this figure describes the **Next.js sidebar**
> (`tblmenumaster_g2g`). It says nothing about the Blade UI, which has its own menu
> tree of 200 rows — 30 of them not present here. **No number needs re-deriving;
> the qualifier travels with it.** See `G-SCOPE-01`.


**Raised by Triz. Distinct from G-SEC-02:** that gap is *no* mapping; this is a
*wrong* mapping, which is more dangerous because it reads as enforced.

Confidence distribution across the 739 routes (confidence = count of shared path
tokens between route URI and menu slug):

| Confidence | Routes | Meaning |
|---:|---:|---|
| 0 | 208 | no menu matched |
| **1** | **~329** | **one shared token — unreliable** |
| 2 | ~156 | plausible |
| 3 | ~43 | strong |
| 4 | 1 | exact |

Sampled confidence-1 errors, all real:

| Route | Mapped to | Why it is wrong |
|---|---|---|
| `PATCH /agents/{id}/status` | Task → **Status Management** | Wrong module entirely — matched on "status" |
| `/lms/governance/permissions` | Org → **Role & Permissions** | Wrong module — matched on "permissions" |
| `/get-employee-tasks` | Org → **Suggestion for employee** | Target is on the DELETE list |
| many competency writes | **Competency Management** | A module **root**, far too coarse to govern a delete |

**Rules adopted:**

1. **Confidence 0 and 1 both count as UNMAPPED.** Only **confidence ≥ 2** may be
   auto-applied.
2. Everything else needs an explicit `->defaults('menu', '…')` in
   `routes/api.php`, **reviewed per controller**.
3. **A route may never map to a container or module-root menu row — leaf screens
   only.** A rights check against a module root cannot distinguish view from
   delete.

Effect: routes requiring manual declaration rise from 208 to **~537 of 739**. That
is the honest number, and it is the real size of the A3 task.

---

## G-SEC-05 — Executing the nav triage will create ungoverned routes · **S1** · `OPEN`

**Raised by Triz.** 50 API routes — **12 of them writes** — currently map to menu
rows on the DELETE or DEFER list, including *Task Assignment & Progress*,
*Suggestion for employee*, *Send SMS to User*, *Assessment List* and *Admin and
Configuration Module*. Delete the row and the route has nothing to check against:
a cleanup task silently manufactures the exact gap G-SEC-02 describes.

**Precondition rule — no nav row is deleted until its routes have a new home.**

For every row scheduled for deletion:

1. List every route mapped to it (from `_evidence/route-to-menu-map.csv`).
2. Decide **per route**: remap to the surviving owner screen, or retire the route
   with the row.
3. Only then execute the row deletion, inside the reversible-SQL + backup process.

To be added as a precondition in `08-connection-plan.md` and folded into the
**Q-A3 amendment 4** process.

---

## G-SEC-03 — No field-level access control · **S2** · `DESIGNED`

Screen-level rights cannot express "an Employee may open the directory but not see
salary", "a reviewee may not read manager private comments", or "360 feedback is
anonymised". Today a screen grant returns the whole record.

**Fix designed:** `03-rbac-matrix.md` §3.8, using named field groups plus an API
resource layer so restricted fields are **absent from the payload**, not merely
hidden by the UI.

---

## G-STR-01 — Four join tables required by the connection layer do not exist · **S1** · `DESIGNED`

`course_competency_map`, `jobrole_competency_map`, `competency` +
`competency_kasba_item`, `jobrole_task_competency_map`. Approved in Q-B4, Q-C1,
Q-C2, Q-C3. Golden threads 2 and 3 cannot be built before they exist.

**Fix:** one coherent schema change with a full ER diagram, per your instruction.
→ `02-domain-model.md`.

---

## G-STR-02 — ~~Three~~ **TWO** tables had migrations recorded as run but did not exist · **S1** · ✅ **CLOSED 2026-08-07** (`7df8c1c7`)

> ### ⚠️ ONE OF THE THREE WAS NEVER MISSING — the record dropped a prefix
>
> `s_competency_certification_requirements` **exists and holds 15 rows**, and
> `CertificationRequirementController` references it correctly. **This register
> recorded it as `competency_certification_requirements` — without the `s_`
> prefix — and it has read as missing since Gate A on that basis.**
>
> **Nothing ever broke.** The gap was recorded from a NAME, not from a QUERY.
>
> **A gap recorded from a name rather than from a query can be wrong for months** —
> this one was. The other two (`competency_evidence`, `s_skill_jobrole`) were
> genuinely absent and are now created.

### Original entry, retained

`competency_evidence`, ~~`competency_certification_requirements`~~, `s_skill_jobrole`.

> ⚠️ **CORRECTED 2026-08-07 (D-007): TWO of three, not three.**
> `s_competency_certification_requirements` — **with** the `s_` prefix — **exists
> and holds 15 rows**, and `CertificationRequirementController` references it
> correctly. **This record listed it without the prefix**, which is why it read as
> missing. **A naming error in the audit, not a missing table.** The other two were
> genuinely absent and are now created.
Controllers and models reference all three; `CertificationController`,
`CertificationRequirementController` and `EmployeeCompetencyProfileController`
break on the paths that touch them.

This is why Certifications appears connected to nothing.

**Fix approved (Q-B5):** restore all three, with root-cause analysis and a
recurrence guard. Root cause is almost certainly the shared database — two
migration systems over one schema (Q-C4). → `02-domain-model.md` §7.

---

## G-STR-03 — No reporting line exists · **S1** · `DESIGNED`

`tbluser` has no manager column; `hrms_departments` has no head. Consequently
*Team* and *Department* scope cannot be resolved, no approval flow can be modelled,
and seven modules have each invented their own local `manager_id`.

**Fix designed:** `03-rbac-matrix.md` §2.4 — `tbluser.reporting_manager_id`,
`hrms_departments.head_user_id`, with cycle validation and depth bounding as step 4.

---

## G-STR-04 — No event, listener or observer mechanism · **S1** · `OPEN`

`app/Events`, `app/Listeners` and `app/Observers` do not exist. `app/Jobs` holds one
unrelated job. Every cross-module flow in the brief §6 requires a mechanism that has
never been built.

**Fix:** build natively in G2G, harvesting the `hpbrain_event_store` design per
Q-C4. → `05-data-flow-contracts.md`.

---

## G-DATA-01 — Courses have no competency link · **S1** · `OPEN`

`sub_std_map` carries `jobrole` as **longtext holding a role name** (73 of 96 rows)
and `proficiency` (2 of 96). No skill or competency foreign key exists.

Without it there is nothing for auto-recommendation to point at, and golden threads
2 and 3 are unbuildable. Approved as the **highest-priority item in the connection
plan** (Q-B4).

---

## G-DATA-02 — Employee Directory has no competency mapping · **S2** · `OPEN`

The brief's stated biggest confusion. The screen has a `skills: []` array and one
"Skill Deficit" KPI counting employees with no skills *or* no profile name. The
chain employee → job role → framework → required KASBA → current proficiency → gap
→ development plan does not exist. It is not broken; it was never built.

---

## G-DATA-03 — Duplicate concepts, 10 pairs · **S2** · `PARTIAL`

D1–D10 in `01-inventory.md` §6. D1 (JobRole) and D2/D3 (Skill vs Competency)
resolved by Q-A1 and Q-A2. D4–D10 outstanding.

---

## G-NAV-01 — Task Permission menu points at the Priority screen · **S3** · ✅ `FIXED 2026-08-05`

> ⚠️ **SCOPE QUALIFIER (2026-08-07):** this figure describes the **Next.js sidebar**
> (`tblmenumaster_g2g`). It says nothing about the Blade UI, which has its own menu
> tree of 200 rows — 30 of them not present here. **No number needs re-deriving;
> the qualifier travels with it.** See `G-SCOPE-01`.


Menu 219 "Permision" and menu 218 "Priority Management" shared one `access_link`.
The Permission screen was built but unreachable — the root cause of the original
report that Task → Permission does not work.

**Fixed** via the reversible process (Q-A3 amendment 4):

| Step | Artefact |
|---|---|
| Backup | `_changes/backup-tblmenumaster_g2g-2026-08-05.sql` — all 188 rows as replayable INSERTs, verified |
| Change + rollback | `_changes/G-NAV-01-fix-permission-menu-link.sql` |
| Applied by | `_changes/G-NAV-01-apply.php` — transactional, pre-conditions checked, auto-rollback on any mismatch |

Row 219 `access_link` → `/module/task-management/task-permission`, matching what
the frontend already registers (`hooks/content-map-m6.ts:42`). **Data only; no
application code changed**, which matters while Phase 3 is pre-Gate-D.

The path deliberately does **not** follow the `/administration/task-*` shape of its
siblings — that spelling would have required a frontend edit too. Tidying the URL
is recorded as a Gate D item.

**Verified:** the nav cross-reference now reports **zero duplicate `access_link`
values** (previously one), and broken-nav remains 0.
`_evidence/nav-crossref.txt` regenerated.

**G-SEC-05 pre-check applied:** no API route maps to menu 219, so nothing was
orphaned.

---

## G-PERF-01 — Skills matrix report takes ~50 seconds · **S2** · `OPEN`

`/api/reports/employee-directory/skills/matrix` measured at 49.4s and 55.3s. It is
correct and tenant-scoped, just extremely slow. No browser or load balancer waits
that long, so the view effectively never renders, and each call holds a PHP-FPM
worker and a DB connection for the better part of a minute.

Its neighbours return in well under a second, so this is one query. Needs `EXPLAIN`
and almost certainly an index. Relevant because its backend is being harvested into
the consolidated reporting home (Q-A3).

---

## G-FLOW-05 — LMS enrolment happens; learning does not · **S2** · `OPEN` (revised)

Investigated ahead of Gate C at Triz's request, because 1,426 enrolments and 0
certificates looked like it might be a live defect. **It is not.**

### The issuance chain is complete and correct

| Step | Evidence |
|---|---|
| UI | `learning-delivery-workspace.tsx:1138` — `onClaim={() => void claimCertificate()}` |
| Hook | `use-my-learning.ts:458` — `lmsCertificateService.issue(context, courseId)` |
| Service | `services/lms/learning.ts:430` — `POST /lms/learning/certificates` |
| Route | `routes/api.php:719` |
| Controller | `LmsLearningController::issueCertificate` → `insertGetId` on `lms_certificates` |

It is a **manual learner claim**, idempotent, and returns 422 until every content
item in the course is complete. There is also an admin `reissueCertificate` path.

### Why there are zero certificates

| Measure | Value |
|---|---:|
| `lms_course_enroll` — status `enrolled` | **1,425** |
| `lms_course_enroll` — status `completed` | **1** |
| `lms_content_progress` — total rows | **1** |
| — of which `in-progress` | 1 |
| — of which `completed` | **0** |
| Distinct users with any content progress | **1** |
| Courses that have content | 79 |

**Nobody has ever completed a single content item.** The certificate gate has
therefore never been satisfiable, and zero is the correct output.

### What this actually means

1. **No live defect. No customer can have been promised certificates by this
   system** — none were ever issuable. Nothing to fix on a separate timeline.
2. **The real finding is a funnel collapse.** 1,426 enrolments produced 1 progress
   row. Assignment works; consumption does not happen. Whether that is because the
   player is hard to reach, because courses have no content worth consuming, or
   because the product has not been used in earnest, cannot be told from the data
   alone.
3. **It changes how golden thread 3 must be judged.** "Learn → prove → level up"
   currently has no observed instance of even the first step. Any design that
   assumes completion data exists is designing against an empty table.
4. **Certificate claiming should probably not be manual.** A learner who finishes a
   course and is not offered the certificate will not go looking for a button.
   Recommend: issue automatically on completion, keep the manual claim as a
   fallback. → carry into `05-data-flow-contracts.md` as `course.completed`.

**Not Phase 3 scope to fix the funnel** — that is a product and content question.
Recorded so the connection plan does not assume completion data that is not there.

---

## G-QUAL-01 — No stable sort on report queries · **S4** · `OPEN`

`/reports/employee-directory/attrition` orders by a value with many ties and no
tiebreaker, so identical requests return rows in different orders. Cosmetic in the
UI, but it defeats response comparison and produced a false "cross-tenant leak"
during Phase 1 testing. Add a deterministic tiebreaker.

---

## Summary

| Severity | Count | IDs |
|---|---:|---|
| **S1** | **9** | G-SEC-01, G-SEC-02, **G-SEC-04**, **G-SEC-05**, G-STR-01, G-STR-02, G-STR-03, G-STR-04, G-DATA-01 |
| **S2** | 4 | G-SEC-03, G-DATA-02, G-DATA-03, G-PERF-01 |
| **S3** | 1 | G-NAV-01 |
| **S4** | 1 | G-QUAL-01 |
| **Total** | **15** | |

*(Correction **C1**: the previous summary said 6 while listing 7. Count and list
now agree, and both include the two findings Triz raised.)*

Five of the nine S1 items are access-control gaps that compound each other:
G-SEC-01 is the exposure, G-SEC-02 and G-SEC-04 are why the fix cannot simply be
switched on, and G-SEC-05 is a way the cleanup work could widen it.

### Whole-register count

The table above covers the **core register only**. Items found later are appended
below rather than renumbered, so the running total is:

| Section | S1 | S2 | S3 | S4 | Total |
|---|---:|---:|---:|---:|---:|
| Core register (above) | 9 | 4 | 1 | 1 | **15** |
| Appended during Gate B | 2 | 3 | 1 | 0 | **6** |
| Appended during Gate C — Libraries & Taxonomy (`G-LIB-01..08`) | 1 | 3 | 3 | 1 | **8** |
| Appended during Gate C — certifications (`G-CERT-01`) | 0 | 1 | 0 | 0 | **1** |
| Appended during Gate C — Competency Library (`G-COMP-01`) | 1 | 0 | 0 | 0 | **1** |
| **Running total** | **13** | **11** | **5** | **2** | **31** |

Rows and columns both sum to 29. *(First draft of this block said S1=11, S2=9,
S3=6, S4=3 — a breakdown that summed to 29 while disagreeing with every section
count. Recomputed from the section rows. Per **C2**, a severity table that does not
reconcile is the first thing a reviewer attacks.)*

More will be added as the Gate C audit proceeds, one write-up at a time.

---

## Appended during Gate B — one line each, written up at Gate C

*(Per Triz's sequencing instruction: log and continue, do not stop to elaborate.)*

| ID | Severity | One-line |
|---|---|---|
| G-SEC-06 | **S2** | **Approved as a real gap.** Rights value becomes **tri-state (ALLOW / DENY / INHERIT)**, INHERIT default, absent row also = inherit. Same shape on **both** `tblindividual_rights` and `tblgroupwise_rights_g2g`. Resolution order: **individual DENY > group DENY > individual ALLOW > group ALLOW > role default > deny.** Supersedes the 4-step order in `03-rbac-matrix.md` §5A/A6, which did not allow for a group-level DENY. |
| G-QUAL-02 | S3 | The audit tool's own regex silently dropped 52 routes (fully-qualified inline class refs). Fixed, but the class of defect — a checker that under-reports — warrants a second pair of eyes on every audit script before its numbers are quoted. |
| **G-FLOW-05** | **revised → S2 (adoption, not defect)** | **Investigated. Not a silent failure and not an unwired path.** The chain is complete: button → `claimCertificate()` → `lmsCertificateService.issue()` → `POST /lms/learning/certificates` → `issueCertificate()` → insert. It is a **manual learner claim**, gated on all content being complete. Zero certificates is the *correct* output of the data: of 1,426 enrolments, **1,425 are `enrolled` and 1 is `completed`**, and `lms_content_progress` holds **1 row, `in-progress`, for 1 user**. Nobody has ever completed a course's content, so the gate has never been satisfiable. **No customer can have been promised certificates by this system** — none were ever issuable. Real finding is a funnel collapse: enrolment happens (1,426), consumption does not (1). See detail below. |
| **G-DATA-04** | **S1** | **12 `s_skill_matrix` rows contain double-encoded character maps** — a JSON string re-encoded character by character (`{"0":"{","1":"\"","2":"C"...}`), producing 9,068 fake sub-items. 96% of all apparent KASBA "measurements" are this artefact. **The write path that produced them must be found before the normalisation migration**, or it will corrupt the new table too. **B3 — add a WRITE-TIME GUARD as well:** reject or flag any value that parses as JSON whose keys are sequential integers from zero (`{"0":…,"1":…,"2":…}`). That signature is unmistakable — no legitimate KASBA payload has it — and it stops the defect re-entering by a route nobody found. Applies to all four KASBA columns and to `skill_matrix_item` after normalisation. |
| **G-DATA-05** | **S2** | **Capability coverage is 3.0%, not ~11%** — 8 of 264 active users have any measurement. Earlier figure divided rows by total (not active) users. All 8 resolve cleanly, so the problem is reach, not quality. |
| **G-LIB-01** | **S1** | **CONFIRMED BREAK — job roles created in Competency never reach HR.** `JobroleApiController.php:27-28` joins `s_user_jobrole.department_id → hrms_departments.id`; the Job Role form writes `department` as a **string** and never `department_id` (`library-config.ts:214`), so the role has a NULL FK and is absent from HRIT's department listing. **The backend already accepts the column** (`LibraryController.php:81`) — the form simply never sends it. One of the cheapest high-value fixes found. Connection **L-01**. |
| **G-LIB-02** | **S2** | **Master data is unaddressable — nine fields name another entity and store free text.** Department, job level, related skills, job titles, tasks, learning resources, certifications, SME, proficiency levels. Nine fields, nine tables that already exist, **zero foreign keys**. Consequences: renaming a skill or role silently detaches its mappings and ratings (joins are on title/name); delete leaves orphans and the dialog **admits it** (`library-tab.tsx:1136`); each tab's category namespace is private and irreconcilable. Root-cause connection **L-11**. |
| **G-DATA-06** | **S2** | **S-1 QUANTIFIES L-11 for the first time.** Live-schema sweep: **49 populated text columns name an entity this product owns and carry no matching `*_id`**. The four largest relationship tables are joined **entirely by strings**: `s_user_jobrole_task.jobrole` (85,662 rows), `s_user_skill_jobrole.skill` + `.jobrole` (79,295 each), `s_jobrole_skills.skill` + `.jobrole` (62,208 each), `s_jobrole_task.jobrole` (55,961). **~283,000 relationship rows held together by string matching**, so every rename silently detaches data. Also found: **`s_library_map.skill_ids` stores ids AS TEXT** on 3,270 rows. ⚠️ **Known false positives in the 49** — the proxy cannot distinguish *a column that IS the entity's own name* (`hrms_departments.department`, `s_user_jobrole.jobrole`) from *a reference to it from elsewhere*, nor attributes that merely contain the word (`skill_status`, `skill_importance`, `skill_flow`). **Only cross-table references are the finding.** Evidence: `_evidence/sweeps/s1-result.json` |
| **G-LIB-03** | **S2** | **The task CATALOGUE has no competency link; the INSTANCE has one and it is weak.** `s_user_jobrole_task` carries no skill column in either direction, so there is no **role-level** statement of which competencies a task exercises. `task.skill_id` **is** populated — 1,514 of 2,271 (67%) — but hand-picked at creation, so capability can only be inferred from what an individual ticket creator happened to tick. **Already approved as Q-C3 → `jobrole_task_competency_map`** (`02-domain-model.md` §2.1, §10 step 3); logged here as the Library-side evidence, **not as new Gate D scope**. ⚠️ *An earlier draft of this line said "no task→skill link exists anywhere", which contradicted `manager.md` §1.2 and the §3 catalogue-wins rule built on it. Corrected; this miss is why **C6b** exists.* |
| **G-LIB-04** | **S2** | **No import on any of the 8 library tabs** (export on all 8). A customer cannot load their existing skill library; onboarding is manual re-typing. Elevates G-FLOW-03 / Q-C1. Connection **L-10**. |
| **G-LIB-05** | **S3** | **Status fields nothing honours.** Skill `Status` has no consumer (matrix, framework and summary filter on `approve_status='Approved'` only — `RoleMappingController.php:117`, `StudioController.php:293`); `skill_status` has **two writers and zero readers** (`SaveJDController.php:170` is the second); Job Role `Status` is filterable on-screen only. Retiring a skill does nothing. Connection **L-05**, gated on **Q-L2**. |
| **G-LIB-06** | **S3** | **366 lines of finished UI are unreachable.** `library-tab.tsx:1054` gates a `Sheet` on `Boolean(selected)`, but all four `setSelected` calls (400, 413, 422, 1054) pass **`null`**. `library-detail.tsx` is stranded. **Remedy is DELETION, not recovery** — the reachable popup (`library-detail-modal.tsx`, 681 lines, 8 cards) is strictly richer than the panel (366 lines, 2 tabs, associations on only 2 of 8 tabs). Reviving it would create a second parallel view of the same record. Connection **L-03R**; ⚠️ needs deletion approval. *(Supersedes L-03, which proposed recovery — sunk cost is not a reason.)* |
| **G-LIB-07** | **S3** | **Two proficiency vocabularies, mutually unaware.** The Skill tab stores a free-text blob (`library-config.ts:168`) and filters on DISTINCT values of it; the Framework Studio edits `s_proficiency_levels`, writable **only from the legacy Blade form**. Same for the responsibility ladder: `s_level_responsibility` is rendered inches from a *Job Level* field placeholdered `"e.g. L3"` that never references it. Connection **L-04**. |
| **G-LIB-08** | **S4** | **Vocabularies drift by design.** The OpenChoice *"+ Add a new …"* control (`library-form.tsx:54-112`) writes new values as free strings with no master record, while the Department filter reads DISTINCT values off the data itself (`LibraryController.php:1753-1764`) — so one misspelling becomes a **permanent filter option** splitting the dataset. Mirror-image bug: the Job Role filter drops every role with a blank department (`LibraryController.php:1738-1741`), so roles exist it can never show. Connections **L-01/L-02** (a real `department_id` removes both bugs at once). |
| **G-SEC-09** | **S1** · ✅ **FIXED 2026-08-06 (D-003), guard-verified 2→0** | **C15 ANSWERED — a live cross-tenant hole on the Competency Library.** `skillLibraryController::competencyLibraryContext()` (**12 API routes, 24 of the 64 hits**) validates that a token exists via `PersonalAccessToken::findToken()` and then **discards its owner**, taking `sub_institute_id` **and** `user_id` from the request body guarded only by `is_numeric`. That is *verbatim* the pattern `ResolvesApiIdentity`'s own docblock describes as the F-01 bug. **Any valid token from any tenant can read and write any other tenant's competency library by changing one number** — create, edit, archive, restore, export, bulk import. Phase 1 fixed 70 controllers and built the trait; **this one was never migrated onto it**, so remediation is adoption, not design. 3 further API-reachable controllers share the shape (`assignmentController` 11 routes, `jobroletaskcontroller`, `jobroletexonomycontroller`) — candidates, not yet read line by line (R6). Evidence: `_evidence/sweeps/c15-tenant-field.md`. ⚠️ **D-002 sits on top of this hole** — independent fix, both needed. |
| **G-SEC-10** | **S1** · ✅ **FIXED 2026-08-06 (D-004), guard-verified 9→0** | **C27 — "trait present, still reads from the request" is its own, HIGHER severity class.** `PayrollController` (**39 routes, `hrms.php`, salary data**) imports `ResolvesApiIdentity` at line 43, calls **`apiTenantId()` zero times**, and reads tenant from the request at ~18 sites. Its only trait usage is **`apiTokenIsValid()`** — *is this token valid*, not *whose is it*: **C22's proxy defect reproduced inside application code.** Lines 596–603 are explicit: `if ($type == 'API') { $sub_institute_id = $request->input('sub_institute_id'); } else { ...session... }` — **the code knows it is serving an API caller and chooses to trust that caller's tenant claim.** Any token holder can read another tenant's payroll. Four further controllers share the class (`contentLibraryController`, `LmsCourseEnrollController`, `talent_interviewschedules`, `talent_jobposting`) — candidates, unread (R6). **Ranks above the no-trait cases: a missing trait is visibly unfinished; an unused one passes every grep, every checker, and sits on the "done" list.** Evidence: `_evidence/sweeps/c27-trait-present-still-broken.md` |
| **G-MAP-01** | **S1** | **THE CAPABILITY MODEL'S CORE MAPPING TABLE HAS NO CREATION PATH.** `s_user_skill_jobrole` — **79,295 rows, one of the four tables in the 283,126 headline** — can only be edited **cell by cell** via `RoleMappingController::upsertCell`. There is no create flow. Command Center's *"Create Role Mapping"* button maps to `kind:'framework'` (`cm-command-center.tsx:56`) — **the same kind as the "Create Framework" button one line above it** — so it builds a framework and writes nothing to the mapping table. **Consequence: a new tenant cannot build a role→skill mapping through the UI at all.** That is a **GOLDEN-THREAD-1 BREAK** — a new customer cannot reach step one of the capability chain. *(Originally logged as F-3 "two buttons, identical behaviour", which understated it: the issue is not the duplication, it is the absence it reveals.)* **WHERE THE 79,295 ROWS CAME FROM — answered:** `SchoolSetupController.php:392-408` (`signup_api`) **bulk-inserts** them at tenant provisioning, copying `jobrole`, `skill`, `proficiency_level`, `skill_code` from the global seed libraries. **A working bulk-create mechanism already exists** — it is simply only reachable from tenant signup, never afterwards. **This is the same mechanism Q-C1's seed-library import needs.** M-03 re-costs from *"build a mapping UI"* to *"surface an existing bulk path"*. **The finding stands at S1** — a customer who adds a job role after signup still cannot map it — but the fix is materially cheaper than first estimated. |
| **G-SEC-11** | **S1** | **28 further controllers fail the tenant-isolation property under execution.** The C23 read-half guard called 912 GET routes twice as one tenant-7 user, varying only `sub_institute_id`; **48 routes across 30 controllers returned different data.** Beyond the two already known: `assignmentController` (6), `HrmsController` (3), `ExcelAutomationAgentController` (2), `HolidayController` (2), and 24 controllers with one each — spanning Payroll, Leave, Talent, LMS, Task, Org and Audit. **HEADLINE INSTANCE, quote this one:** a tenant-7 employee calling **`GET /api/skills`** with `sub_institute_id=3` receives **297,582 bytes of another organisation's skill library** — against **84,363 bytes** of their own. **3.5× more data from an organisation they have no relationship with.** One concrete example carries more weight with a buyer or a board than a count of 48. **48 is a FLOOR** — 454 GET routes were UNTESTABLE and 864 write routes are untested. **Each of the 37 not corroborated by source reading is a CANDIDATE (R6)**; one (`CompetencyDashboardController@getRoleSimilarity`, differing at identical length) is a likely false positive pending hand check. Worklist: `_evidence/sweeps/c23-worklist.md` |
| **G-CERT-01** | **S2** | **There is no certification TYPE entity — only a policy and a per-person instance.** `s_competency_certification_requirements` expresses *"certification Y is required for role/department/competency X"*; `s_competency_certifications` is the held credential (`user_id`, `issued_date`, `expiry_date`, `credential_id`). **Neither references a catalogue** — both carry their own free-text `name` and `issuing_body`, and the instance has no `certification_type_id`. So two employees holding the same real-world certification are two unrelated strings, no coverage or expiry roll-up can be trusted, and the competency mapping has nowhere correct to live (on the policy = wrong relation; on each instance = two employees could disagree about what one certification means). **Larger than L-09 and its prerequisite.** Design: `02-domain-model.md` §10.1, migration steps 3b and 9b. |
| **G-COMP-01** | **S1** | **The competency approval workflow is optional in four independent ways, and rejection is a dead end.** (1) Create takes `approve_status` **from the client**, defaulting to `Approved` (`skillLibraryController.php:2527`) — stronger than a default; there is no server-side constraint. (2) Edit writes `approve_status` straight through with no reviewer or role check (2587-2589), reachable from a dropdown on the form. (3) **Restore is unconditionally `Approved`** (2222-2223) — archive a Pending competency, restore it, and it is approved with no reviewer recorded. (4) On **reject**, `ApprovalController` marks the approval row rejected but leaves the subject `Pending` (316-332), while the UI hides *Submit for Approval* when status is Pending (`cm-competency-library.tsx:1223`) — so a rejected competency **can never be resubmitted**, and the only escape is bypass (2). The backend approval API works; the only UI that can approve/reject sits in the Audit & Activity Center, which `content-map-m2.ts:17-20` says has **no `tblmenumaster_g2g` row**. **Distinct from G-SEC-01: these bypasses would survive a perfect RBAC fix.** Connections C-01, C-02. |
| **G-SEC-07** | ~~S1~~ → **S3, SUBSTANTIALLY CORRECTED 2026-08-07** | **See the full correction above the provenance section. The original claim — that user roles carry no meaning — was measured on the wrong table and is WITHDRAWN.** |
