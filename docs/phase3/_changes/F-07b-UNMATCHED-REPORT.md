# F-07b — THE UNMATCHED REPORT

**Nothing has been backfilled. Nothing has been dropped. Nothing has been written.**
This is a read-only measurement, and it comes to you before any of that.

Since G-DATA-06 this phase has argued from principle that **283,126 rows resolving
by string** is the headline defect. **This is the empirical measure of what that
cost.**

---

## The number, defined before it is quoted (R10)

**424,630 is not a row count.** It is the number of **individual text→id
resolutions** the backfill must perform: **6 column mappings across 4 tables**, and
two of those tables carry two text keys each.

| | |
|---|---:|
| Rows in the four tables | **283,127** |
| Column mappings to resolve | **6** |
| **Individual resolutions** | **424,630** |

**Quoting 424,630 as "rows" would overstate the problem by 50%.** Quoting 283,127
as "resolutions" would understate the work by the same margin.

---

## The result, by reason

| Reason | Distinct values | Resolutions | Share |
|---|---:|---:|---:|
| **EXACT** | 19,531 | **394,288** | **92.9%** |
| CASE | 6 | 392 | 0.1% |
| WHITESPACE | 0 | 0 | 0.0% |
| NEAR-MISS | 1 | 25 | 0.0% |
| **NO COUNTERPART** | **5,088** | **29,925** | **7.0%** |

**RECOVERABLE (case + whitespace + near-miss): 417 resolutions.**
**LOST (no counterpart): 29,925 resolutions.**

---

## Your prediction, and where it did not hold

You expected the unmatched set to be **larger than intuition suggests**, citing
**514 vs 460 case differences on one term in `s_skill_matrix`**.

**The unmatched set IS large — 29,925 — but almost none of it is recoverable.**
Case, whitespace and near-miss together account for **417 resolutions, 0.1%**. The
failure is not messy spelling. **It is references to things that do not exist.**

**On the source of the expectation:** `s_skill_matrix` has **169 rows and
`skill_id` is 100% populated** — it is already keyed and is **not part of F-07b's
backfill at all**. The figure that set the expectation came from a table this work
does not touch.

> **The prediction was reasonable and the data disagrees. Reported rather than
> reconciled**, because the shape of the failure changes what the fix is: a
> normalisation pass would recover 417 resolutions, and the other 29,925 need a
> decision, not an algorithm.

---

## Per mapping

| Source → canonical | Exact | Case | Near | **No counterpart** | Tenant-scoped? |
|---|---:|---:|---:|---:|:-:|
| `s_user_skill_jobrole.jobrole` → `s_user_jobrole.jobrole` | 77,630 | 0 | 0 | **1,665** | yes |
| `s_user_skill_jobrole.skill` → `s_users_skills.title` | 68,884 | 354 | 0 | **10,057** | yes |
| `s_user_jobrole_task.jobrole` → `s_user_jobrole.jobrole` | 82,802 | 11 | 0 | **2,850** | yes |
| `s_jobrole_skills.jobrole` → `s_user_jobrole.jobrole` | 55,023 | 15 | 25 | **7,145** | **NO** |
| `s_jobrole_skills.skill` → `s_users_skills.title` | 54,067 | 1 | 0 | **8,140** | **NO** |
| `s_jobrole_task.jobrole` → `s_user_jobrole.jobrole` | 55,882 | 11 | 0 | **68** | **NO** |

*All six rows read directly from the generator's output — none reconstructed.*

### The worst single group

**`s_jobrole_skills.skill` — 3,245 distinct skill names with no counterpart**,
carrying 8,140 resolutions. Its tenant-scoped sibling
**`s_user_skill_jobrole.skill` is worse by volume: 10,057 resolutions** across
1,196 distinct names — and that one IS tenant-scoped, so the figure is a
measurement rather than an upper bound. Examples: *"abide by business ethical code of
conducts"*, *"abide by regulations on banned materials"*. These read like an
imported taxonomy that was never reconciled with `s_users_skills`.

---

## A SECOND FINDING, and it changes how the first should be read

**Three of the six mappings have NO `sub_institute_id` on the source table.**
`s_jobrole_skills` and `s_jobrole_task` carry no tenant column at all.

Requirement 1 says *match within tenant*. **For those three mappings it is not
possible** — the source does not know which tenant it belongs to. The report
matched them **across all tenants**, which is the most generous possible reading.

> **So their match rates are UPPER BOUNDS, not measurements.** A value that matched
> a canonical row in tenant 4 may belong to a row whose real owner is tenant 9, and
> nothing in the data can distinguish those cases.
>
> **These two tables cannot be safely backfilled as they stand.** Establishing
> which tenant each row belongs to is prior work, not part of the backfill.

That affects **125,314 rows** — 44% of the four tables.

---

## What I recommend, and what I am NOT doing

**Not doing:** any backfill, any drop, any write. Requirement 4 stands — text
columns stay until the new columns are proven, and R8 governs every drop when we
reach one.

**Recommended sequence, for your decision:**

1. **Backfill the three tenant-scoped mappings only** — 240,900 resolutions, of
   which **229,316 match exactly (95.2%)**. Safe, verifiable, and it covers the
   majority. The 13,777 that do not match are held, not guessed.
2. **Normalise the 417 recoverable** — a case/whitespace pass, worth doing because
   it is small and unambiguous.
3. **The 29,925 with no counterpart need a DECISION, not an algorithm.** They are
   references to job roles and skills that are not in the canonical tables. Either
   the canonical tables are incomplete, or these rows are dead. **That is the
   question this report exists to put in front of you**, and it is the empirical
   answer to what the string-matching era cost.
4. **`s_jobrole_skills` and `s_jobrole_task` are BLOCKED** on the tenant question
   above. They are not a backfill problem.
