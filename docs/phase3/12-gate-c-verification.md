# 12 — Gate C verification · an audit of the audit

**Not a re-read.** The 3,378 elements are not re-opened — that is C18, deliberately
traded away. This verifies **the claims the connection plan rests on**, and reports
an **error rate**, not an assurance.

**Status:** `V1 and V6 complete. V2–V5, V7, V8 in progress.`

> ## ⚠️ V6 FOUND FOUR ERRORS IN EIGHT HEADLINE NUMBERS
> Three share a single root cause, and **they run in the opposite direction to
> every previous error in this phase.** Details in §V6. **Do not quote the old
> figures.**

---

# V1 — CLAIM INVENTORY

Extracted from the six module write-ups. The denominator for everything below.

| Class | Count | What it means |
|---|---:|---|
| **STRUCTURAL** — schema, route, column; mechanically checkable | **86** | file:line references, table/column existence, route declarations |
| **NEGATIVE** — *"does not exist / not connected / nothing reads this"* | **31** | **the ones that become build work** |
| **NUMERIC** — a count or percentage | **19** | the quotable figures |
| **BEHAVIOURAL** — what happens at runtime | **14** | needs execution or careful reading |
| **Total claims the plan depends on** | **150** | |

Per module: competency 44 · lms 38 · task 34 · other 33 · talent 31 · organization 23
*(table-bearing rows; a row may carry more than one claim, so 150 is the
claim count, not the row count).*

**39 connection items** across the six write-ups feed §5 of the plan.

---

# V6 — HEADLINE NUMBERS RE-DERIVED BY A SECOND METHOD

**R17 applied first:** every number was checked against an existing artefact before
any new script was written. `c23-result-FULL-912.json` answered the guard figures
without a re-run; `s1-result.json` held the per-table counts.

| # | Number as published | Re-derived | Verdict |
|---:|---|---|---|
| 1 | **283,126** rows string-joined | **283,127** | ❌ **CORRECTED** |
| 2 | **3.0%** capability coverage | **2.7%** | ❌ **CORRECTED — overstated** |
| 3 | **66.7%** `task.skill_id` | 66.7% (1,514 / 2,271; 757 null, sums exactly) | ✅ **CONFIRMED** |
| 4 | **46** guard FAILs | 46 | ✅ **CONFIRMED** |
| 5 | **30** controllers affected | **29** | ❌ **CORRECTED — overstated** |
| 6 | **1,676** routes | **1,683** (router) | ⚠️ **two valid methods — see below** |
| 7 | **912** GET routes | 912 (router) | ✅ **CONFIRMED** |
| 8 | **864** write routes / **430** never audited | **772** write routes | ❌ **CORRECTED — overstated** |
| 9 | **3** confirmed leaks, 2 fixed | 3 (2 fixed, guard-verified green) | ✅ **CONFIRMED** |

## The root cause of three of the four

> **Numerator and denominator computed with different filters.**

### 283,126 → **283,127**

The sum mixed **one populated-column count into a sum of row counts**.
`s_user_jobrole_task` has **85,663 rows**, of which **85,662 have a populated
`jobrole`** — one row has an empty key. I used 85,662 for that table and row counts
for the other three.

**Both figures are defensible; mixing them is not.**

| Statement | Figure |
|---|---:|
| **rows in the four tables** | **283,127** |
| rows with a populated string key | 283,126 |

**Use 283,127** and describe it as *rows*. The difference is one row and changes
nothing material — **but a headline number quoted to a board must be derived one
way.**

### 3.0% → **2.7%** · the material one

`8 of 264`. The denominator **264** is confirmed correct and well chosen:
`status = 1 AND deleted_at IS NULL`.

**The numerator was not filtered the same way.** Applying the *same* filter to both:

| | |
|---|---:|
| active, not-deleted users | **264** |
| **of those**, with any capability measurement | **7** |
| **coverage** | **2.7%** |

The 8th measured user is **inactive or soft-deleted**. Counting them in the
numerator while excluding them from the denominator **overstates coverage**.

**2.7%, not 3.0%.** Immaterial to any decision — every conclusion drawn from it
("the chain will be structurally correct and visibly empty") holds identically.
**Material to credibility**, which is why it is corrected here.

### 30 → **29** controllers

Not a miscount — a **stale pairing**. "48 FAILs across 30 controllers" was correct.
D-003 fixed `skillLibraryController`'s 2, taking FAILs to **46** — and removing that
controller entirely, taking controllers to **29**. I updated the first number and
carried the second forward.

**46 FAILs across 29 controllers.**

### 864 → **772** write routes

**Method 1** (regex over route files, counting `resource` as 4 writes) gave 864.
**Method 2** (Laravel's own router, the authoritative list of what actually serves)
gives **772**.

**The router wins.** The regex over-counted resource expansion.

⚠️ **The derived claim "430 write routes never audited" is therefore also wrong and
is WITHDRAWN.** It was `864 − 434`. It needs recomputing from the router, per route
file, and **until then no write-route coverage figure is quoted.**

### 1,676 vs 1,683 — both valid, different questions

| Method | Figure | Measures |
|---|---:|---|
| Regex over the six route files | 1,676 | **declarations written** |
| Laravel router | **1,683** | **routes actually registered** |

Neither is wrong. **1,683 is the better figure for "how big is the surface"**,
because it is what serves traffic. R10: state which is meant.

---

## The direction of these errors — and it has flipped

**R11's tally was 13 under-reports, 0 over-reports.** Every previous error made a
finding look *smaller* or a risk look *safer*.

**Three of V6's four go the other way:**

| Error | Direction |
|---|---|
| 3.0% vs 2.7% coverage | **overstated the good news** *(more coverage than exists)* |
| 30 vs 29 controllers | **overstated the finding** |
| 864 vs 772 write routes | **overstated the unaudited surface** |
| 283,126 vs 283,127 | understated by one row — immaterial |

### Why the flip, and why it matters

**The under-reports came from scope-narrowing assumptions during investigation** —
R11's mechanism: a smaller scope is adopted more readily because it looks tidier.

**These over-reports came from presentation.** They are all numbers written *into a
document to be quoted*, and each was assembled by combining figures derived at
different moments with different filters. **Nobody re-derived them end to end until
now, because they had already been "verified" individually.**

> **R19 — a number assembled from other numbers is a NEW claim and must be
> re-derived end to end, with one filter, before it is published.**
> Verifying each input separately does not verify their combination.

**Both directions are wrong.** Under-reporting hid risk; over-reporting inflates
findings a customer or investor will check. The second is the one that costs
credibility.

---

## Corrected figures for §1 of the connection plan

| Use this | Not this |
|---|---|
| **283,127 rows** across four tables | ~~283,126~~ |
| **2.7%** capability coverage (7 of 264) | ~~3.0%~~ |
| **46 guard FAILs across 29 controllers** | ~~30 controllers~~ |
| **1,683 registered routes · 912 GET · 772 with a write verb** | ~~1,676~~ · ~~864~~ |
| **write-route audit coverage: NOT YET DERIVED** | ~~430 never audited~~ |
| 66.7% `task.skill_id` · 3 confirmed leaks, 2 fixed | *(confirmed)* |

---

*V2–V5, V7 and V8 follow in the next pass. Exit criteria are assessed once V4's
sample error rate exists.*
