# C10 — cross-cutting sweeps: status, results, and **conclusion**

---

# ⭐ THE SWEEP PROGRAMME'S CONCLUSION

**Seven sweeps. Two verified and productive. Five retired or unvalidatable.
BOTH productive ones were STRUCTURAL.**

| Sweep | Kind | Outcome |
|---|---|---|
| **S-1** free text where a key belongs | **structural** | ✅ **Produced `G-DATA-06` — 283,126 rows, the phase headline** |
| **S-4b** state gates never satisfied | **structural** | ✅ **Proved a negative** — the dead-panel pattern occurred exactly once |
| S-3 client supplies a server-owned field | structural | known-positive named; unvalidated |
| S-6 multiple writers to one table | structural | known-positive named; unvalidated |
| S-2 payload vs validator | **behavioural** | 🗄️ **RETIRED (C35)** — failed twice; needs 3-hop cross-file dataflow |
| S-5 divergent vocabularies | **behavioural** | 🗄️ **RETIRED (C36)** — missed the very defect it was built to rediscover |
| S-4 unreachable components | structural | **no known-positive can exist**; superseded by S-4b |
| S-7 no-op handlers | **behavioural** | **no known-positive exists**; C36 retirement |

## The lesson, which is worth more than several of the sweeps were

> **Structural questions get tools. Behavioural questions get a careful reading by
> a person.**

A structural question — *does this column exist, is this state ever set, does this
class have a file* — is answerable from one artefact, and a tool answers it
exhaustively and cheaply. **Both productive sweeps were of this kind.**

A behavioural question — *does the payload this form sends survive the validator,
do two screens mean the same thing by "Archived"* — requires following meaning
across files. **Every behavioural sweep failed**, and two failed while a human
reading had already found the exact defect they were built to find.

**The C13 structural/behavioural split predicted every case**, including which of
the four unvalidated sweeps cannot have a known-positive at all. It is now the
standing test for whether to build an instrument: *can this be answered from one
artefact?* If not, put it on the module-write-up checklist instead.

---


**Method change approved 2026-08-06.** Pay for pattern detection once across all 32
sub-modules, instead of paying for enumeration per sub-module.

**R4 governs every sweep.** No first number is quoted. Each sweep is hand-verified
on a sample of hits **and** non-hits before publication. Scripts live beside this
file and are re-runnable.

| Sweep | Script | Status | First number | **Verified number** |
|---|---|---|---:|---:|
| **S-1** free text where a key belongs | `s1-freetext-keys.php` | ✅ **VERIFIED** | 49 | **36** (C30 corrected split 36/5/8) — produced **G-DATA-06**, the phase headline |
| **S-2** form sends a field the server drops | `s2-dropped-fields.php` (+ v1 `.py`) | 🗄️ **RETIRED WITH REASON (C35)** | 111 endpoints | **NOT QUOTED.** Converted to a module-write-up checklist item |
| **S-3** client supplies a server-owned field | `s3-client-owned.py` | **RAW — not verified** | 184 | **pending** |
| **S-4** components unreachable by import | `s4-unreachable.py` | **RUN, low yield** | 4 | 4 (see caveat) |
| **S-4b** state gates never satisfied | `s4b-stategate.py` | ✅ **VERIFIED** | 9 | **1** |
| **S-5** two screens, one table, divergent vocabularies | `s5-divergent-vocab.py` | 🗄️ **RETIRED WITH REASON (C36)** | 8 columns | **0 confirmed.** Behavioural; missed its own known-positive |
| **S-6** multiple writers to one table | `s6-writers.py` | **RAW — not verified** | 27 tables | **pending** |
| **S-7** no-op handlers | `s7-noop.py` | **RUN, low confidence** | 10 | 10 (see caveat) |

**7 of 7 run. 2 verified · 1 retired with reason · 4 awaiting their R16 known-positive.**

### The structural / behavioural split, borne out

| Kind | Sweeps | Outcome |
|---|---|---|
| **STRUCTURAL** — schema, imports, state | **S-1**, **S-4b** | **Both worked.** S-1 produced **283,126**, the phase headline; S-4b **proved a negative** (the dead-panel pattern is not systemic) |
| **BEHAVIOURAL** — what code does at runtime across files | **S-2**, **S-5** | **Both struggled.** S-2 failed twice; S-5 missed the very defect it was built to rediscover |

A structural sweep reads one artefact and answers a question about it. A behavioural
sweep must follow meaning across files, which regex cannot do. **Expect at least two
of the remaining four to be better as checklist items** — that is the
classification working, not the sweeps failing. Stated plainly because a
half-finished sweep quoted as finished is exactly the failure R4 exists to prevent.

---

## R16 retrofit — known-positives named for the four unvalidated sweeps

**Naming only this round.** Validation follows, and **C36 applies to each: if a
sweep fails its gate, do NOT rebuild it — ask whether a bespoke tool is cheaper
than a checklist item in the module write-ups, and report the answer.**

| Sweep | Kind | Named known-positive | Prediction under the structural/behavioural split |
|---|---|---|---|
| **S-3** client supplies a server-owned field | **structural** (single-file pattern) | `skillLibraryController.php:2527` — `'approve_status' => $request->input('status','Approved')`, confirmed and now fixed by D-002 | **Should pass.** The pattern is visible in one file, one line. Its 184 hits then need only the subject-vs-identity classification, not a rebuild |
| **S-4** components unreachable by import | **structural** | *(none available)* — the one real instance, `library-detail.tsx`, **was imported**, so S-4 structurally cannot see it. **S-4b was written precisely because S-4 has no known-positive** | **Cannot be validated.** S-4 keeps its 4 low-value hits and is superseded by S-4b, which has one |
| **S-6** multiple writers to one table | **structural** | `s_users_skills` — 4 writers, independently confirmed as Gate A duplication **D2** | **Should pass.** Then the open question is only whether Eloquent writes are missed |
| **S-7** no-op handlers | **behavioural** | *(none available)* — no confirmed dead button exists in the audit. Its 10 hits are unmatched against anything known | **Likely a C36 retirement.** A handler that updates state and never calls an API is invisible to a regex, exactly like S-2 |

**Two of the four have no known-positive that can exist** (S-4, S-7) — which is
itself the C36 answer for them, before any validation run. That is the
classification predicting the outcome rather than discovering it.

---

## S-2 — 🗄️ RETIRED WITH REASON (C35), not failed and not owed

**Decision: do not build v3.** Two implementations failed and the diagnosis is
sound — it needs real cross-file dataflow, three hops across two files, a
materially bigger tool than any other sweep.

**Weighed against what it buys:** the defect class it targets has **already been
found by hand, twice**, in the Command Center write-up — by reading a form config
and its controller. A tool is not cheaper than the reading that already found it.

**Replaced by a standing item on the module write-up checklist:**

> *For each form in this module: does the payload it sends match what the validator
> accepts and what the insert writes? **Name the three files read.***

No tool, covers all 32 sub-modules, and it happens inside work already scheduled.

**Both failed implementations are kept in `_evidence/` with their diagnoses**,
because *why* S-2 cannot be a regex is itself a finding about this codebase: the
payload, the endpoint constant and the handler live in three different files, so
no single-file pattern can connect them.

### The record of the two attempts

**Output NOT quoted, per R16.** The sweep reported 111 endpoints; the gate says its
sensitivity is undemonstrated, so the 111 means nothing.

### The gate, built into the script

```
=== R16 KNOWN-POSITIVE GATE ===
*** NOT DETECTED. The sweep has NO demonstrated sensitivity.
*** Per R16 its output is NOT quoted.
```

**Known-positive:** `POST /competency/competencies` → `CompetencyController@store`
drops `competency_type` and `jobrole` (competency-library.md §2.1). The sweep did
not find it.

### Why v1 failed, and why v2 failed differently

| | Defect |
|---|---|
| **v1** | Asked *"is this key accepted **anywhere** in the backend?"*. `competency_type` **is** accepted — by `skillLibraryController`, a **different endpoint**. A global check is **structurally incapable** of finding a per-endpoint drop |
| **v2** | Went per-endpoint, but collected "sent keys" from **any frontend file containing the endpoint string**. The payload for `/competency/competencies` is built in `cm-command-center.tsx`; the **endpoint string lives in `command-center.ts`**. They never co-locate |

**The garbage is visible in the output and is the proof:** endpoints "dropping" 160
keys including `per_page`, `last_page` and `range_label` — **response** fields, not
payload fields. Had the gate not existed, 111 endpoints and a scary-looking count
would have been reported.

### What a working v3 needs

Real cross-file dataflow: follow the **payload variable** from the component that
builds it, through the service function, to the endpoint constant it posts to.
Three hops, two files. That is a materially bigger tool than the other sweeps and
should be costed as such (**R7 — name the files**), not attempted a third time by
regex.

**Recorded as owed, not as done.** S-2 is the only sweep with a named
known-positive it cannot see.

---

## S-5 — runs, but its grouping is wrong · **0 confirmed findings**

8 status-like columns have ≥2 writers with divergent vocabularies. **None is
confirmed**, and the reason is a defect in the sweep, not a clean result.

**The proxy groups by COLUMN NAME across ALL TABLES.** So `status` shows **63
writers** — because `task.status`, `lms_course_enroll.status` and
`hrms_emp_leaves.status` are unrelated columns that happen to share a name. That is
noise by construction, not divergence.

### The one hit that looked real, and was not

`approve_status`: 5 writers, union `Approved / Pending / Rejected / approved`.
Two controllers (`jobroleLibrary1Controller`, `jobroleLibraryController`) emit
**lowercase `approved`** — and consumers filter on `approve_status='Approved'`, so
lowercase rows would be **invisible**. That would have been a live defect on the
exact column D-002 changed.

**Checked against the data (R3): it is not one.**

| `s_users_skills.approve_status` | rows |
|---|---:|
| `'Approved'` | 3,973 |
| `NULL` | 2 |
| `'Pending'` | 1 |

**No lowercase rows exist.** The lowercase writers target a **different table** —
exactly the conflation the grouping causes.

*(Incidental confirmation: the single `Pending` row and the 2 NULLs predate D-002,
whose new writes are the first to produce `Pending` deliberately.)*

**Fix required before S-5 is quotable:** group by **(table, column)**, not column.
The writer→table mapping already exists in `s6-writers.py`. Recorded rather than
patched blind, because the corrected version may find real divergence — the
Command Center defect that motivated this sweep is real and this run did not
rediscover it.

---

## S-4b — the only sweep verified end to end

### Result: **1 hit in the entire frontend.** `library-tab.tsx:206`.

That is the already-known dead panel (G-LIB-06). The valuable part is the negative:
**the dead-panel pattern is not systemic.** It happened once.

### The first number was 9. Seven of the eight extras were my checker's fault.

Hand-checking two of the nine found **two distinct checker bugs**:

| # | Bug | Example |
|---:|---|---|
| **7** | **A setter passed by reference is a real call site.** `onSelect={setSelected}` (`certifications-records.tsx:399,520`) and `.then(setVerification)` (line 660) never appear as `setSelected(` , so the regex called them uncalled | `certifications-records.tsx` — 2 false hits |
| **8** | **The initial `useState` value was ignored.** `useState(true)` starts *satisfied*; `setLoading(false)` merely turns it off | `tm-integrations.tsx:27`, `tm-permissions.tsx:29` — 2 false hits |

Both fixed in the script; the corrected run drops 9 → 1.

**These are R4 cases 7 and 8. The tally is now eight disagreements, eight times the
tool was wrong, zero times the codebase was.**

### Why S-4 and S-4b are both needed

S-4 walks the **import graph** and found 4 never-imported exports — all small
utilities (`SidebarSkeleton`, `InlineSpinner`, `RightFloatingToolbar`,
`GlobalError`), none material.

**S-4 structurally cannot find the dead panel**, because `library-detail.tsx` *is*
imported and *is* rendered — inside a `Sheet` whose open condition is never true.
Import reachability and runtime reachability are different questions, and the
finding that prompted this sweep lives only in the second. Recorded so the same
mistake is not made on the remaining sweeps.

---

## S-3 — raw, and the raw number is misleading

**184 hits. Do not quote it.** The pattern is
`'server_owned_field' => $request->input(...)`.

| Field | Hits |
|---|---:|
| `sub_institute_id` | 64 |
| `status` | 43 |
| `created_by` | 33 |
| `user_profile_id` | 23 |
| `user_id` | 11 |
| `approval_status` | 3 |
| `profile_id` | 3 |
| `approve_status` | 2 |
| `verification_status` | 1 |
| `role` | 1 |

**A large share will be legitimate**, and I can already name the class:
`tblmobileAppMenuRightsController` and `tbluserProfileWiseMenuController` account
for ~14 `user_profile_id` hits, and in a rights-administration screen the profile
id is **the subject being configured**, not the caller's own role. That is correct
behaviour, not a defect.

The subset that looks genuinely dangerous — where the field describes the
**caller's own authorisation state**:

| File | Line | Code |
|---|---:|---|
| `skillLibraryController.php` | 2527 | `'approve_status' => $request->input('status', 'Approved')` — **already G-COMP-01** |
| `CompetencyController.php` | 104 | `'approve_status' => $request->input('status') === 'published' ? …` — **already G-COMP-01** |
| `CertificationController.php` | 390 | `'verification_status' => $request->input('verification_status', 'pending')` — **a credential that verifies itself** |
| `assignmentController.php` | 416, 472 | `'approval_status' => $request->decision` |
| `AgentController.php` | 386 | `'role' => $request->input('role')` |

**Not yet published as findings.** Each needs its guard checked — an authorised
admin endpoint that takes a decision from the request body is correct. Verification
is the next step.

The `sub_institute_id` = 64 group is separate and matters: that is the **F-01
tenant field**, fixed in Phase 1 for the controllers then in scope. Whether these
64 are inside or outside that fix is an open question, not a finding.

---

## S-6 — raw

**27 of 121 tables have more than one writing file.** Highest counts:

| Table | Writers |
|---|---:|
| `task` | 10 |
| `tbluser` | 9 |
| `hrms_departments` | 4 |
| `content_master` | 4 |
| **`s_users_skills`** | **4** — `CompetencyController`, `DepartmentSkillController`, `SchoolSetupController`, `skillLibraryController` |

`s_users_skills` confirms and **extends** Gate A's D2: the audit found two writers,
the sweep finds **four**.

**Known limitations, stated before the number is used:** the script matches
`DB::table('x')->…insert/update/delete` within 400 characters. It does **not** see
Eloquent `Model::create()`, `->save()`, or writes split across more than 400
characters — so **non-hits are not proof of a single writer.** Verification of both
hits and non-hits is pending.

---

## S-7 — low yield, low confidence

10 hits: 6 empty arrow handlers, 3 `noop`, 1 `console.log`-only. No always-disabled
buttons, no TODO handlers.

**The low number should not be read as "few dead buttons".** The regexes catch only
the *literal* no-op. The common real form — a handler that updates local state and
never calls an API — is invisible to them, exactly as S-4 was blind to the dead
panel. S-7 needs a second method before its number means anything.

---

## What this changes about the plan

**S-4b is the proof of concept and it worked**: one pass over the whole frontend,
one verified answer, and a *negative* result (the pattern is not systemic) that 32
separate write-ups would have produced far more slowly and less reliably.

It also shows the cost honestly: **the sweep took one pass; verifying it took two
hand-checks and a script fix, and that is where the value was.** A sweep is cheap;
a *trustworthy* sweep is not. The remaining six should be budgeted accordingly.

**Next:** run S-1, S-2, S-5; verify S-3 and S-6; then build the C11 coverage ledger
and re-project per C12.
