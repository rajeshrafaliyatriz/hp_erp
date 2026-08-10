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


---

# ADDENDUM — Q-C1 ANSWERED, ORPHANS PROFILED, BACKFILL APPLIED

## 1. The two tenantless tables — the right question, answered

You were right that "no `sub_institute_id`" is **Q-C1's decision, not a gap**, and
that my framing was the wrong question. The right one — *what are they matching
against?* — has a decisive answer.

**They resolve to TENANT-OWNED canonical rows, not global ones.** Both
`s_user_jobrole` and `s_users_skills` are tenant-scoped, and tenant 1 holds the
bulk (2,875 of 4,610 jobroles; 2,395 of 3,976 skills) — it looks like the seed
tenant, but it is **a tenant, not a global namespace**.

**And the same string exists in many tenants at once:**

| Mapping | Unambiguous | **AMBIGUOUS** | Worst case |
|---|---:|---:|---:|
| `s_jobrole_skills.jobrole` | 1,972 | **785** | matches **9 tenants** |
| `s_jobrole_task.jobrole` | 1,971 | **785** | matches **9 tenants** |
| `s_jobrole_skills.skill` | 1,480 | **617** | matches **10 tenants** |

> **STOP, on your criterion.** A "match" here is not a resolution — it is a choice
> among up to ten equally valid ids. Writing one would blend the seed library into
> one customer's data, which is exactly what Q-C1 separated.
>
> **These two tables ARE blocked — but for a better reason than I first gave.**
> "Establishing tenant is prior work" was right in conclusion and under-argued.
> The real finding is that **the canonical tables are per-tenant, so a global
> table cannot key into them at all** without deciding *whose* copy it means.

## 2. Orphan concentration — the answer differs BY MAPPING

| Mapping | Orphans | Shape |
|---|---:|---|
| `s_user_skill_jobrole.skill` | 9,332 | **SPREAD** — tenants 3 (28.7%), 9 (20.8%), 5 (19.1%), 6 (19.1%), 2, 11 |
| `s_user_skill_jobrole.jobrole` | 1,665 | **CONCENTRATED** — tenant 9 holds **89.9%** |
| `s_user_jobrole_task.jobrole` | 2,861 | **CONCENTRATED** — tenant 9 holds **76.4%** |

**Both hypotheses are true, of different mappings.** The jobrole orphans are
**seed junk from one import** — tenant 9. The skill orphans are **genuine
incompleteness**, spread across six tenants.

**Tenant 1 has almost no orphans** (0.1% and 0.4%), consistent with it being where
the canonical data was authored.

> **Different answers, so different fixes:** tenant 9's jobroles are one import to
> review; the skills gap is a curation task across six customers.

## 3. The backfill — applied, and its numbers CORRECTED

| Mapping | Rows | Exact | Recovered | **HELD as NULL** |
|---|---:|---:|---:|---:|
| `s_user_skill_jobrole.jobrole_id` | 79,295 | 77,630 | 0 | **1,665** |
| `s_user_skill_jobrole.skill_id` | 79,295 | 68,884 | **354** | **10,057** |
| `s_user_jobrole_task.jobrole_id` | 85,663 | 82,802 | **11** | **2,850** |
| **TOTAL** | **244,253** | **229,316** | **365** | **14,572** |

**Every figure matches the report's prediction exactly** — 77,630 / 69,238 /
82,813 populated, against 77,630 / 68,884+354 / 82,802+11 predicted. **The report
was a measurement, not an estimate.**

### Two numbers in my recommendation were wrong (R19)

I wrote *"240,900 resolutions, 13,777 unmatched"*. **Actual: 244,253 and 14,572.**
And *"417 recoverable"* is the total across **all six** mappings; within the three
backfilled it is **365**. Corrected here rather than left to be found.

### Verification

**Sample — the populated id resolves to the record the text named:** five random
rows across all three mappings, **all IDENTICAL**, tenant matching on both sides
(1/1, 8/8, 11/11, 5/5).

**Whole-population integrity — cross-tenant foreign keys: 0, 0, 0.** Not a sample:
every populated id was checked against its canonical row's tenant.

### What was NOT done

**No canonical rows created. Nothing deleted. No text column dropped.** The 14,572
unmatched are **held as NULL** — the fact that the text names something which does
not exist is now stored rather than guessed away.

### One operational note

The first backfill used `BINARY` in the JOIN's `ON` clause, which **defeats every
index** — it ran three minutes and populated zero rows. Rewritten so the indexed
columns do the join and `BINARY` is a residual `WHERE` filter. Same semantics,
and it completes. The stuck query was killed by id; no data was affected.


---

# ADDENDUM 2 — EXCLUDED BY DESIGN, AND THE ORPHANS ARE ONE DEFECT NOT TWO

## `s_jobrole_skills` and `s_jobrole_task` — EXCLUDED BY DESIGN. Question closed.

**Not blocked. Never resolvable, and correctly so.**

785 job role names exist in up to **nine** tenants. A global table **cannot key
into per-tenant canonical tables at all** — not "not yet", but never, without
inventing an answer to *"whose copy?"*.

**That is Q-C1 working, not failing.** `s_jobrole_skills` is a **SEED LIBRARY
customers import from**; `jobrole_competency_map` holds the tenant-owned version.
**A library's text keys are correct AS TEXT** — it is a catalogue of names, not a
relationship table.

> **Resolution belongs at IMPORT TIME, into the importing tenant's own rows,
> where there is exactly one right answer.** Folded into Q-C1's seed-library
> import feature.

**No `*_id` columns were added to either table** — the F-07b migration touched only
the two tenant-scoped tables. **Nothing to remove under R8.**

## THE ORPHANS ARE THE SAME DEFECT — the "genuine incompleteness" reading is WRONG

You directed one check on the jobrole orphans. **I ran it on the skill orphans
too, and the assumption did not survive.**

| Orphan set | Distinct names | **Present in a library** |
|---|---:|---:|
| `s_user_skill_jobrole.jobrole` | 99 | **99 — 100%** in `s_jobrole` (global, 3,347 rows) |
| `s_user_jobrole_task.jobrole` | 116 | **116 — 100%** in `s_jobrole` |
| `s_user_skill_jobrole.skill` | 434 | **433 — 99.8%** in the seed library |

> ### Every orphan set is the SAME fixable import defect
>
> **An import created RELATIONSHIPS without creating the tenant's own CANONICAL
> COPIES.** The names were never invented and are not junk — they are all in a
> library. The importer wrote the join rows and skipped the master rows.
>
> **The fix is re-running that import correctly, for all three** — not curation,
> not review, and not two separate tracks.

**"Spread vs concentrated" describes WHICH TENANTS imported badly, not what went
wrong.** Tenant 9 did it worst on job roles; six tenants did it on skills. Same
defect, different blast radius.

**Corrects my own earlier framing too:** I called the skill orphans "an imported
taxonomy never reconciled with `s_users_skills`". **It is the reverse** — the
taxonomy is fine and the tenant's copy of it was never created.

**Still held: NULL ids, text retained, nothing created, nothing deleted.**
