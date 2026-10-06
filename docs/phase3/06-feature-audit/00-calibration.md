# 06 — Feature audit: C1 + C1b calibration

**Answers C1: measure the raw inventory's error rate on one sub-module before
auditing the other ~3,200 rows.** C1b repeats the exercise on the *hardest* unit,
because one point on the easy case does not bound the real rate. The two results
are reported separately and are **not** averaged.

| | Unit | Source shape | Rows | Real errors |
|---|---|---|---:|---:|
| **C1** | Competency → Library & Taxonomy | declarative config | 159 | **0** |
| **C1b** | Competency → Development & Career Path | 2,604-line imperative component | 206 | **0** |

Sub-module chosen: **Competency → Library & Taxonomy** (first in the C2 golden-thread
order). **159 rows** — every row in the raw inventory citing a `library-*` source
file (`library-config.ts` 102, `library-tab.tsx` 32, `library-detail-modal.tsx` 19,
`library-form.tsx` 6).

*(An earlier draft of this line also said "or `taxonomy-manager`". It did not: that
file's 13 rows were outside the filter. 159 is `library-*` alone. Corrected here
because a calibration document that misstates its own denominator is worthless.)*

---

## Method

Two independent passes, because R1 requires it and because structural and semantic
correctness are different claims.

### Pass 1 — structural, mechanical (all 159 rows)

`_evidence/verify-inventory.py` checks three things per row:

| Check | Question |
|---|---|
| **A** file | Does the cited source file exist? |
| **B** line | Is the cited line number within that file? |
| **C** api | Does the cited endpoint exist in `routes/api.php`? |

### Pass 2 — semantic, by hand

Read the actual source and confirm the row's *claim*, not just its coordinates.
Structural validity does not make a description true.

---

## Result

| Check | Pass | Fail | Rate |
|---|---:|---:|---|
| **A** — file exists | 159 | 0 | **100%** |
| **B** — line in range | 159 | 0 | **100%** |
| **C** — endpoint exists | 34 | 0 | **100%** |
| **Rows with ≥1 real failure** | — | **0 of 159** | **0.0%** |

### The three "failures" were mine, not the inventory's

The first run reported **24 failures (15.1%)**, the second **3 (1.9%)**. Both were
defects in my checker:

| Run | Reported | Cause |
|---:|---:|---|
| 1st | 24 | Literal URI matching. `GET /competency/library/kasa/knowledge` was called undeclared, but the route is `kasa/{type}` — a concrete segment must match a route parameter |
| 2nd | 3 | The inventory elides long prefixes as `.../kasa/{type}/{id}/usage`. My parser counted `...` as a path segment, so the lengths never matched. **`/usage` exists at `routes/api.php:314`** |
| 3rd | **0** | — |

**Rule R1 earned its place again.** Quoting the first number would have condemned a
sub-module that turned out to be entirely accurate — and would have triggered a
pointless re-derivation of 3,200 rows.

### Semantic spot-check — 2 of 2 correct

Two of the highest-stakes claims, verified by reading the source:

| Claim | Verdict |
|---|---|
| *"Job Role tab writes `department` as free text and never `department_id`"* | **TRUE.** `library-config.ts:214-215` — fields are `department` and `sub_department`; no `department_id` anywhere in the tab config |
| *"No form field links a Knowledge item to a Skill, a Job Role or a level"* | **TRUE.** The Knowledge form's fields are `key_concepts`, `theoretical_foundation`, `complexity_level`, `proficiency_expectation`, `references`, `certification_options`, `compliance_relevance`, `knowledge_tags` — no skill, role or level reference |

A third claim — *"the same table is written by `cm-competency-library` through a
different endpoint"* — was independently verified during Gate A as duplication
**D2**, before this inventory was consulted.

---

## C1b — the hard unit

**Unit:** Competency → Development & Career Path Workspace.
**Source:** `cm-development-career.tsx`, **2,604 lines**, a single imperative React
component holding five tabs, four dialog forms and three tables. This is the
adversarial case for an agent-generated inventory: no declarative config to read
off, deeply nested JSX, and handlers defined far from the elements that call them.

**Rows: 206. Verified at 100%, not sampled**, as C1b requires.

| Check | Pass | Fail | Rate |
|---|---:|---:|---|
| **A** — file exists | 205 | 1 | 99.5% |
| **B** — line in range | 205 | 0 | **100%** |
| **C** — endpoint exists | 142 | 5 | 96.6% |
| **Rows with ≥1 *real* failure** | — | **0 of 206** | **0.0%** |

### All six failures were the checker's, again

R4 applied cleanly — it named the culprit before I looked.

| Reported failure | Ruling |
|---|---|
| `PUT /competency/development-plans` not declared (×2) | **Checker wrong.** The route is `PUT /competency/development-plans/{id}` — `routes/api.php:394`. The inventory writes *"POST or PUT /competency/development-plans"* as ordinary shorthand: POST to the collection, PUT to the member. My parser applied both verbs to the collection URI |
| `PUT /competency/career-paths` not declared (×2) | **Checker wrong.** Same shorthand; the route is at `routes/api.php:408` |
| `PUT /competency/development-plans/{id}/actions` not declared | **Checker wrong.** Same shorthand; `routes/api.php:399` is `.../actions/{actionId}` |
| *"no parseable file:line"* on the Learning edit-controls row | **Checker wrong.** The row cites `LearningAssignmentController.php:250-255` — a **backend** file. My indexer only walks the frontend tree, so any legitimately backend-anchored row is unparseable to it by construction |

An earlier run of this same unit reported **10 failures (4.9%)** from a naive `api`
parser that could not read compound notation such as
`"GET /competency/employee-options; POST/PUT with user_id_target + jobrole"` or
`"POST/PUT due_date"` — the latter naming a *payload field*, not an endpoint.

### Semantic verification — the negative claims, 2 of 2 correct

Per C6 these are the expensive ones to get wrong, so both were read in full:

| Claim | Verdict |
|---|---|
| *"Learning kebab menu offers only status changes and removal; type and due date cannot be edited"* | **TRUE.** `cm-development-career.tsx:1854-1869` — the menu maps `LEARNING_STATUS_OPTIONS` to *"Mark …"* items, then a separator, then *"Remove Assignment"*. Nothing else. The backend `update()` **does** accept `assignment_type` and `due_date`, so this is a real UI gap over a working endpoint |
| *"`AssignLearningForm` has no competency/gap selector"* | **TRUE.** The whole function body contains no competency field and no gap reference — learning is assigned without recording which gap it closes |

### What C1b changes

**Nothing about the plan — and that is the finding.** The hard unit scored
identically to the easy one. Two points, opposite ends of the difficulty range,
both **0**. Re-derivation (C1's step 5, threshold 10%) is **not** triggered.

Caveat 1 of C1 — *"this is the most structured unit, imperative components may
score worse"* — has now been **tested and not borne out**. It is retired.

The residual risk is no longer "did the inventory hallucinate?" It is
**"did the inventory omit?"** — a silent failure neither pass detects, since both
only test rows that exist. That risk is carried in the gap register as **G-QUAL-02**
and is why each write-up re-walks its source file rather than trusting the row count.

---

## Verdict and consequence

**C1: 0 of 159. C1b: 0 of 206. Semantic checks 4 of 4 correct.**

Per C1, that is a low error rate, so **the remaining rows may be spot-checked
rather than re-derived.**

### How the remaining ~3,200 rows will be handled

| Step | Applies to |
|---|---|
| 1. Run `verify-inventory.py` over **every** unit | all 15 units — cheap, mechanical, catches hallucinated files and endpoints |
| 2. Hand-verify **every row whose claim drives a decision** | any row cited in a gap, a connection, or a golden thread |
| 3. Hand-verify **100% of every negative claim** — anything asserting a thing is *missing, absent, unimplemented, not connected, mock, no-op or dead* | **C6.** These are the accusations. They are what the buyer will act on and what a developer will contest, and being wrong about one costs more than being wrong about ten descriptive rows |
| 4. Hand-verify a **10% random sample of the remaining (positive) rows** | detects semantic drift the mechanical pass cannot see |
| 5. Re-derive any sub-module whose sample error rate exceeds **10%** | the escape hatch if a unit turns out worse than these two |

Step 3 supersedes the narrower earlier rule. A claim of absence cannot be proved by
reading the row; it can only be proved by searching the source for the thing said to
be absent and finding nothing. Every such claim in every write-up gets that search,
and the write-up cites where it looked — not merely what it concluded.

#### C6b — a negative claim has **two** legs, not one

**Every "does not exist / not connected / nothing consumes this" claim is checked
against BOTH the code AND the decisions already taken** in `02-domain-model.md`,
`05-data-flow-contracts.md` and `10-open-questions.md`. Both legs must pass.

| Leg | Question | Failure mode it catches |
|---|---|---|
| **1 — code** | Does the thing exist in the codebase? | hallucinated absence |
| **2 — decisions** | Has this project already found, decided, or scheduled something about it? | **a true statement about the code that contradicts a settled finding** |

Leg 2 exists because **L-14 passed leg 1 and still had to be withdrawn.** It said
*"no task→skill column exists anywhere."* The catalogue column really is absent —
leg 1 clean. But `manager.md` §1.2 had already verified `task.skill_id` populated on
**1,514 of 2,271 instances**, and `02-domain-model.md` §3 built the *"catalogue
wins, instance is a confidence-tagged override"* rule on top of it. The claim was
locally true and globally false.

Two costs, both real:

1. **A withdrawn finding damages the whole document.** A reader who catches one contradiction re-opens everything.
2. **Gate D would have counted the same work twice** — L-14 was already approved as Q-C3's `jobrole_task_competency_map` and already sits in the §10 migration sequence.

**Therefore every write-up carries a `New work versus already-approved work` table**
reconciling each proposed connection against Gate B, in the shape set by
`competency-library-taxonomy.md` §5.1. In force **from the Competency Library
write-up onward**; applied retroactively to Libraries & Taxonomy.

The generalisation: leg 1 asks *"is this true?"*, leg 2 asks *"is this new?"* — and
an audit that only ever asks the first will keep re-discovering its own conclusions.

### One honest caveat

**Both passes only test rows that exist.** Neither can detect a control the
inventory never catalogued. That is the live risk (**G-QUAL-02**), and the reason
each write-up re-walks its source file top to bottom rather than treating the row
list as complete.

*(C1's first caveat — that the easy unit might not represent the hard ones — was
tested directly by C1b and did not hold. Retired.)*

---

## Status

`Analysis Done` — **C1 and C1b both complete and both clean.** The audit proper
begins at C2 order item 1 (Library & Taxonomy write-up) under the step 1–5 method
above.
