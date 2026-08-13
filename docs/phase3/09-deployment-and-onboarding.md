# DEPLOYMENT AND ONBOARDING — running G2G in our own company

> # THE MIGRATIONS BUILD THE SCHEMA. NOTHING BUILDS THE SCREENS.
>
> **`php artisan migrate` on a fresh database produces a system with the complete
> Phase 3 structure and NO WAY TO REACH IT.** Menus 224–229, their rights rows, and
> the `catalogue_task_id` column exist only in one-off scripts under
> `docs/phase3/_changes/`, run by hand against the shared test database. They are
> not in a migration and not in a seeder.
>
> | asked of the seeder | answer |
> |---|---|
> | Competency Definitions (227) | **NOT IN SEEDER** |
> | Course Competencies (228) | **NOT IN SEEDER** |
> | Task Competencies (229) | **NOT IN SEEDER** |
> | Readiness / Terminology menus | **NOT IN SEEDER** |
> | `catalogue_task_id` in any migration | **NO** — only in `f10a-catalogue-task-id.php` |
>
> **This is the single largest gap between "Phase 3 is done" and "Phase 3 can be
> installed", and it was invisible until the seeder was asked directly.** Every
> proof this phase produced was run against a database that already had the menus,
> because the script that made them ran months of turns ago and never left the
> working directory.
>
> **It is a day of work, not a redesign** — §b.1 says exactly what to write. But it
> must be written before any fresh database is stood up, or the first thing we will
> discover is that we cannot log in and see our own product.

---

## §a. SCHEMA DELTA — an ordered, runnable migration set

**18 migrations, already ordered by timestamp, already runnable as one
`php artisan migrate`.** Run in this order; the order is the filename order.

| # | migration | kind | additive? |
|---|---|---|---|
| 1 | `2026_08_04_120000_align_s_skill_matrix_with_live_schema` | ALIGN | ⚠️ **NO** — reshapes an existing table |
| 2 | `2026_08_07_100000_phase3_foundation_join_tables` | CREATE | ✅ additive |
| 3 | `2026_08_07_110000_add_tristate_rights_columns` | ADD COLUMN | ✅ additive |
| 4 | `2026_08_07_120000_add_reporting_line_and_role_keys` | ADD COLUMN | ✅ additive |
| 5 | `2026_08_10_140000_create_g2g_event_store` | CREATE | ✅ additive |
| 6 | `2026_08_10_150000_create_g2g_audit_log` | CREATE | ✅ additive |
| 7 | `2026_08_10_160000_create_task_status_history` | CREATE | ✅ additive |
| 8 | `2026_08_10_170000_add_fk_columns_for_backfill` | ADD COLUMN | ✅ additive |
| 9 | `2026_08_10_180000_fix_kasba_item_label` | **DATA FIX** | ⚠️ **NO** — rewrites rows |
| 10 | `2026_08_10_190000_create_competency_kasba_rating` | CREATE | ✅ additive |
| 11 | `2026_08_11_000100_create_g2g_notification_tables` | CREATE | ✅ additive |
| 12 | `2026_08_11_000200_add_learning_assignment_provenance` | ADD COLUMN | ✅ additive |
| 13 | `2026_08_11_000300_correct_notification_action_paths` | **DATA FIX** | ⚠️ **NO** — UPDATEs rows |
| 14 | `2026_08_11_000400_declare_competency_referent` | ADD | ✅ additive |
| 15 | `2026_08_11_000500_declare_remaining_referents` | ADD | ✅ additive |
| 16 | `2026_08_11_000600_add_competency_to_performance_goals` | ADD COLUMN | ✅ additive |
| 17 | `2026_08_11_000700_suggested_course_task_optional` | **RELAX NOT NULL** | ⚠️ **NO** — alters a column |
| 18 | `2026_08_11_120000_create_tenant_readiness_gate_table` | CREATE | ✅ additive |

**14 of 18 are purely additive.** On a fresh database all 18 are safe, because the
four non-additive ones operate on rows that will not exist yet — **an ALIGN with
nothing to align and a data FIX with nothing to fix are both no-ops.** They are
listed as non-additive because on OUR existing test database they are not, and
because a future customer with existing data will meet them for real.

### THE THREE STRUCTURAL CHANGES THAT ARE **NOT** IN THIS SET

**These will not run and must be added before any install:**

| missing | where it lives now | consequence if forgotten |
|---|---|---|
| `catalogue_task_id` column + index | `_changes/f10a-catalogue-task-id.php` | the task→competency path falls back to a **name join**, the exact defect F-10 was raised to remove |
| menus **224–229** rows | `_changes/menu-*.php` | **no navigation to any Phase 3 screen** |
| rights rows for those menus (~33 per menu) | `_changes/menu-2NN-rights.php` | menus exist and every profile is denied |

**Migration 19 must be written**: `add_catalogue_task_id_to_task` — the column and
index only. **The BACKFILL must stay a script**, not a migration: it is row-by-row
by design (see §a.1) and a migration that takes hours is a migration that will be
killed halfway by someone who thinks it has hung.

### §a.1 WHY THE BACKFILL IS SLOW, AS A DESIGN CONSEQUENCE

**A bulk `UPDATE … JOIN` cannot refuse an ambiguous pair.** 5,470 rows have a title
that names two catalogue entries. A set-based statement would have silently picked
one and keyed them all, and the result would have been indistinguishable from a
correct key — **wrong data that looks exactly like right data.** Row-by-row is what
"held, never guessed" costs. It was paid deliberately. It is **not a slow script to
be optimised away.**

### DOES ANY MIGRATION ASSUME TEST-DATABASE DATA?

**No — but two assume EXISTING TABLES they do not create.** The 18 migrations call
`Schema::table()` on 18 pre-existing tables including `tbluser`,
`tbluserprofilemaster`, `task`, `s_skill_matrix`, `hrms_departments` and
`competency_kasba_item`. **They are a DELTA on top of the base product, not a
standalone schema.** The base migrations must run first; on a fresh database
`php artisan migrate` does that automatically by timestamp.

---

## §b. WHAT MUST BE SEEDED vs WHAT WE AUTHOR

**This is the difference between a migration and a data-entry job, and the line is
sharper than it looks: STRUCTURE IS ANYTHING A CUSTOMER CANNOT CREATE THROUGH A
SCREEN.**

### b.1 SEEDED — structural, ships with the product

| thing | rows | where it must come from |
|---|---|---|
| menus 224–229 | 6 | **new seeder — DOES NOT EXIST YET** |
| rights rows for 224–229 | ~33 × 6 ≈ 200 | **new seeder — DOES NOT EXIST YET** |
| profile master rows (admin, hr_manager, hr_executive, employee…) | ~8 | `Phase3RoleSeeder` ✅ exists |
| the base menu tree (1–223) | ~220 | `TblMenuMasterSeeder` ✅ exists (2,461 lines) |
| base groupwise rights | many | `TblGroupwiseRights` ✅ exists |
| KASBA type vocabulary (the five dimensions) | 5 | code constant `ITEM_TABLES` ✅ |
| event catalogue | — | `EventCatalogue` ✅ code |
| readiness gate definitions | — | `2026_08_11_120000` ✅ migration |

> **THE WORK: write `Phase3MenuSeeder` by lifting the INSERTs out of the six
> `_changes/menu-*.php` scripts.** They already contain the exact rows, already
> proved against a live database, already with their reversal statements. This is
> transcription, not design — **but until it is done the product cannot be
> installed anywhere it has not already been installed by hand.**

### b.2 AUTHORED BY US — data entry, no seeder should ever ship it

| thing | why it is ours |
|---|---|
| organization / sub-institute | it is our company |
| departments | ours |
| job roles | ours |
| tasks per job role | ours |
| courses | ours |
| **the competency framework itself** | **the product's core value and it must not be guessed for a customer** |
| KASBA items (knowledge/ability/skill/behaviour/attitude) | ours |
| competency → job role requirements | ours |
| course → competency map | ours |
| job-role task → competency map | ours |
| employee ratings | ours, continuously |

**The 226 KASBA items and 56 course-competency rows currently in the test database
are SEED NOISE from provisioning. They must not be treated as a starting framework
and must not be copied into a real tenant.** A competency framework that arrived
by accident is worse than an empty one, because nobody knows which rows were meant.

### b.3 THE HONEST LINE

> **The five empty tables are UNAUTHORED, NOT UNFINISHED.** Every one now has a
> working writer with a screen behind it. What they do not have is content, and
> content is the customer's — in this case, ours.

---

## §c. THE ORDER A REAL TENANT IS BUILT IN

**Each step names its screen. Where there is no screen, it says so and names the
script instead — that is the part Milan must build or run.**

| # | step | screen | status |
|---|---|---|---|
| 1 | **Run migrations** (18 + the new 19th) | — | `php artisan migrate` |
| 2 | **Seed menus + rights** | — | ⚠️ **SCRIPT — SEEDER NOT WRITTEN (§b.1)** |
| 3 | **Create the organization / sub-institute** | Organization setup | ⚠️ **verify: provisioning-only on the test DB** |
| 4 | **Departments** | Organization → Departments | ✅ screen exists (`hrms_departments`) |
| 5 | **Job roles** | Organization → Job Roles | ✅ screen exists |
| 6 | **Tasks per job role** | Task Management → Job Role Tasks | ✅ screen exists |
| 7 | **Users** | HRMS → Employee | ✅ screen exists; bulk import for volume |
| 8 | **Profiles + rights per user** | Settings → Rights | ✅ screen exists (tri-state) |
| 9 | **Reporting lines** | HRMS → Employee (reports-to) | ✅ column added by migration 4 |
| 10 | **Courses** | LMS → Courses | ✅ screen exists |
| — | **— the capability chain begins here —** | | |
| 11 | **KASBA items + competencies** | **menu 227 Competency Definitions** | ✅ **built this phase** |
| 12 | **Competency → job role** | **Role Requirements panel** | ✅ **built this phase** |
| 13 | **Course → competency** | **menu 228 Course Competencies** | ✅ **built this phase** |
| 14 | **Job-role task → competency** | **menu 229 Task Competencies** | ✅ **built this phase** |
| 15 | **Backfill `catalogue_task_id`** | — | ⚠️ **SCRIPT** `f10a-catalogue-task-id.php --backfill` |
| 16 | **Employee ratings** | rating endpoint / assessment screens | ✅ endpoint proved |
| 17 | **Gap analysis, heatmap, reports read the chain** | Competency dashboards | ✅ reads correctly at every link |

**Steps 1, 2 and 15 have no screen and never will.** Steps 3 needs verifying —
every tenant in the test database arrived by provisioning, so **no one has ever
created one through the product**, and that is a materially different claim from
"the screen exists".

**Order matters at exactly one place: 11 → 14.** A task-competency map cannot be
authored before the competencies exist. Everything before step 11 is ordinary HR
setup and can be done in any sensible order.

---

## §d. WHAT IS NOT READY FOR REAL USE — plainly

**What a real tenant would actually experience today:**

### d.1 PERMISSIONS ARE ENFORCED BY ROUTE MIDDLEWARE ONLY

**The matrix guard is not registered.** `RequireApiToken`, `RequireProfile` and
`RequireMenuRight` are registered and do work — a caller without a token is
refused, and a caller with the wrong profile is refused. **What is NOT enforced is
the per-cell rights matrix**: the tri-state columns exist, the rights screen writes
them, and **no server-side check reads them on every request.**

> **Practical consequence: a user who can reach a module can generally use it.**
> Rights are honest at the navigation layer and advisory at the data layer. For US
> as first customer this is acceptable — we know our own staff. **For a paying
> customer it is not, and it must not be described as if it were.**

### d.2 TWO IDENTITY READS STILL CONSULT THE SESSION

Found while committing Milan's 50 files, unfixed because they are his:

```
session()->get('sub_institute_id') ?? $this->apiTenantId($request) ?? 3
```

**Session ahead of token, falling back to a hardcoded 3 — the demo tenant.** On a
fresh install tenant 3 will be something else entirely, or nothing. **This must be
fixed before we onboard ourselves**, and it is a two-line fix in a file we now
control.

### d.3 EMAIL IS OFF

`G2G_NOTIFY_EMAIL=false`, gated at every send path and asserted by the suite.
**Nothing will be emailed to anyone: no invitations, no assignment notices, no
reminders.** In-app notifications work. **For our own first tenant this is the
correct setting** and should stay false until we have a tenant of our own
addresses. The three conditions to flip it are unchanged and all three are still
required.

### d.4 THREE ITEMS BLOCKED, AND THEY ARE NOW UNBLOCKED

**S-03, S-08 and O-04 were blocked on the 50 uncommitted files. Those files are now
committed** (this turn), so the block is lifted. They are not done — they are
merely no longer blocked, which is a different sentence and the honest one.

### d.5 WHAT THE VERIFICATION SUITE CANNOT TELL US

**The suite runs at one instant and the system does not.** Hysteresis, certification
expiry, delivery idempotency and never-auto-lowers are claims about a sequence,
each carrying a check about an instant. **It is not that the time dimension fails;
it is never asked.** Running ourselves for a quarter is the first real test any of
those four will ever get.

---

## §e. THE FIRST CUSTOMER IS US — what I expect us to hit first

**In the order I expect it, and the first three are near-certain:**

**1. We will not be able to see the new screens.** If the database is fresh, menus
227/228/229 will not exist (§b.1). **This will look like "the feature wasn't
built".** It is the seeder. Expect it on day one, before anything else.

**2. We will be logged in as a tenant that is not ours.** The hardcoded `?? 3`
(§d.2) will resolve to the demo tenant or to nothing. **Symptom: a screen that
shows someone else's data, or an empty screen that should have data.**

**3. Every capability screen will be empty, and it will feel broken.** All five
chain tables ship at zero. The screens say `empty_is_expected` in the payload and
the UI says so on screen — **that copy was written for exactly this moment** and it
is the difference between "waiting for you" and "broken".

**4. We will author competencies before job roles and have to redo it.** The chain
requires 11→14 in order (§c). It is the one ordering constraint and it is not
enforced anywhere.

**5. Someone will reach a module their rights should have blocked.** §d.1. It will
not look like a bug; it will look like it works.

**6. `catalogue_task_id` will be NULL for every task we create.** The backfill is a
one-off script; **new tasks created through the screen need the column populated at
write time**, and if the writer does not set it we will silently rebuild the
name-join we just spent F-10a removing. **Check this on the first task we author.**

**7. Nothing will be emailed and someone will report it as a fault.** §d.3. It is
the correct setting. Say so before it is reported, not after.

### WHY BEING FIRST CUSTOMER IS THE ADVANTAGE

**Every one of the seven above would otherwise be found by someone paying us.**
Items 1, 2 and 6 are install-time defects that no amount of code review finds,
because they only exist in the gap between a working system and a *fresh* one —
**and this engagement has never once run against a fresh one.**

> **THE PROOFS ALL RAN AGAINST A DATABASE THAT ALREADY HAD WHAT THE INSTALL DOES
> NOT CREATE.** That is the same shape as every finding this phase produced: not a
> check that failed, but a question that was never asked.

---

## THE FIRST FOUR THINGS TO DO, IN ORDER

1. **Write `Phase3MenuSeeder`** — transcribe the six `_changes/menu-*.php` scripts. *Blocks everything.*
2. **Write migration 19** — `catalogue_task_id` column + index only, backfill stays a script.
3. **Fix the two session reads** (§d.2) — two lines, now in files we control.
4. **Stand up a fresh database and run 1–17 of §c end to end**, on a scratch schema, with no production and no customer data. **That run is the first honest test of the install, and it will find more than this document predicts.**
