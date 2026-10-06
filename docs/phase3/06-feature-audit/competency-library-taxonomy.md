# Competency → Libraries & Taxonomy

**Gate C write-up 1 of N.** C2 golden-thread order, item 1.
**Status:** `Analysis Done` — no code changed.

| | |
|---|---|
| **Screen** | Competency → Libraries & Taxonomy (8 tabs) |
| **Frontend** | `cm-libraries-taxonomy.tsx`, `libraries/library-config.ts` (the tab definitions), `library-tab.tsx`, `library-form.tsx`, `library-detail-modal.tsx`, `library-detail.tsx`, `taxonomy-manager.tsx`, `levels-of-responsibility.tsx` |
| **Backend** | `Api/Competency/LibraryController.php` |
| **Tables written** | `s_users_skills`, `s_user_jobrole`, `s_user_jobrole_task`, plus four KASA tables and one "Invisible" table |
| **Inventory rows in scope** | **176** |
| **Calibration** | This is the C1 unit. Structural error rate **0 of 159** |
| **Connections** | **23** (L-01…L-23) — 13 new, 5 already approved, 2 bind to existing fields, 3 other |

---

## 0. Verification performed on this unit

Recorded first, because under R4 a finding is only as good as the method that
produced it, and the reader is entitled to know which claims were checked and how.

| Claim class | Rows | Method | Result |
|---|---:|---|---|
| Structural (file, line, endpoint) | 176 | `verify-inventory.py`, then every reported failure investigated under R4 | **0 real errors** |
| **"No consumer" field claims** | **25** | **100% (C6).** Mechanical sweep of both codebases for each column name, then hand-inspection of every non-zero hit | **24 of 24 TRUE.** A **25th** column (`bussiness_links`, misspelt) was missed by the sweep and re-checked separately — see §6. Not a clean zero, but no behavioural consumer |
| High-stakes structural negatives | 5 | 100%, read in source | **5 of 5 TRUE**, one with a correction (§4) |
| Remaining rows | 147 | Structural pass + targeted reads where a row drives a connection | no contradictions found |

### The "no consumer" sweep, in detail

24 form fields are catalogued as written-but-never-read. Each column name was
searched across `app/`, `routes/`, `resources/` and the entire frontend. Every hit
outside the library files was opened by hand. **Nothing was a consumer:**

| Apparent hit | What it actually was |
|---|---|
| `userskill.php:12,15,16` | `$fillable` — a **write** path |
| `services/competency/libraries.ts:139,149,150` | a **TypeScript interface** declaring the API response shape. A type is not a reader |
| `services/competency/skill-detail.ts:86` | the word *"references"* **in a comment** |
| `EmployeeCompetencyProfileController.php:181` | reads `$user->total_experience` — a **different column on a different table** |
| ~90 hits on `experience`, `benefits`, `purpose`, `references` | other modules' own identically-named columns (recruitment, onboarding, LMS). Same word, unrelated data |

That last row is exactly the trap R4 exists for: a naive count would have reported
these fields as "connected" and quietly buried the finding.

---

## 1. What this screen is

Eight tabs over one shared component. `library-config.ts` is a **declarative
config** — each tab supplies its table, its columns and its form fields, and
`library-tab.tsx` renders all eight from that description.

| Tab | Table | Taxonomy? |
|---|---|---|
| Skill | `s_users_skills` | yes |
| Job Role | `s_user_jobrole` | yes |
| Job Role Task | `s_user_jobrole_task` | yes |
| Knowledge / Ability / Attitude / Behaviour | four KASA tables | yes |
| Invisible | its own table | **no** |

**This is good architecture and it should be said plainly.** One config, one
renderer, eight entities. Adding a ninth library is a config entry, not a screen.
The problems below are *not* problems with this design — they are problems with
what the config declares.

---

## 2. The finding, stated once

> **This screen is the organisation's master data, and almost none of it is
> addressable. Fields that name another entity are stored as free text, so nothing
> downstream can join to them.**

Everything in §3 is a consequence of that one sentence.

Counted precisely: of the 176 rows in scope, **93 are marked not connected and 33
partially connected** — and the dominant cause is not a missing screen or a missing
endpoint. It is a missing **key**.

| Field says it names… | Stored as | The table that should have been referenced |
|---|---|---|
| a department | free string | `hrms_departments` |
| a job level | free string (`"e.g. L3"`) | `s_level_responsibility` — **displayed on the same screen** |
| related skills | free string | `s_users_skills` |
| job titles needing this skill | free string | `s_user_skill_jobrole` — the real mapping table |
| tasks for this skill | free string | `s_user_jobrole_task` |
| learning resources | free string | LMS courses |
| certifications | free string | `lms_certificates` |
| an SME | free name | the employee record |
| proficiency levels | free-text blob | `s_proficiency_levels` |

Nine fields, nine tables that already exist, zero foreign keys.

### The three second-order consequences

1. **Joins are on strings, so renaming silently destroys data.** Every consumer of a skill joins on its **title**; every consumer of a role joins on its **name**. Editing either detaches the record's mappings and its employee ratings, with no cascade and no warning. The edit form offers no hint that the field is a join key.
2. **Delete is worse, and the product admits it.** The confirmation dialog says *"Anything already mapped to it keeps its existing record"* (`library-tab.tsx:1136`). Deleting a skill leaves role mappings and employee ratings pointing at a title that no longer exists. No impact count is shown, though the usage query needed to compute one already exists.
3. **Each tab's taxonomy is its own private namespace.** A `Safety` category on Skill and a `Safety` category on Knowledge are unrelated strings that can never be reconciled. Renaming a category does not propagate to `s_competency_framework_weights.category`, to `s_user_skill_jobrole`, or to any report keyed on it.

---

## 3. Verified defects

Ordered by cost to the buyer, not by size of fix.

### D1 — Job roles created here are invisible to HR · **CONFIRMED BREAK**

`JobroleApiController.php:27-28` lists roles by joining
`s_user_jobrole.department_id → hrms_departments.id`. The Job Role form writes
`department` **as a string** and never `department_id` (`library-config.ts:214`).
A role created here therefore has a NULL `department_id` and **never appears in
HRIT's department listing.**

**The backend is not the problem.** `LibraryController.php:81` already accepts
`department_id` on this tab. The column is writable today; the form simply does not
send it. This is one of the cheapest high-value fixes in the whole audit.

### D2 — A 366-line detail panel is unreachable · `dead`

`library-tab.tsx:1054` renders a `Sheet` gated on `Boolean(selected)`.
`setSelected` is called at lines **400, 413, 422 and 1054 — all four pass `null`.**
Nothing ever passes a row. The panel cannot open.

Stranded behind it: `library-detail.tsx`, **366 lines of maintained UI**, and a
backend read model that line 225 would have queried.

**But recovering it is the wrong fix.** The popup that *is* reachable
(`library-detail-modal.tsx`, 681 lines, 8 cards) is strictly richer — see §5.4. The
defect is real; the remedy is **deletion**, not revival. Tracked as **L-03R**.

### D3 — Two proficiency vocabularies, unaware of each other

The Skill tab's *Proficiency Levels* is a **free-text blob on the skill row**
(`library-config.ts:168`). The Framework Studio edits `s_proficiency_levels`, a real
per-skill scale table — writable only from the **legacy Blade form**. The Skill
tab's Proficiency Level *filter* reads DISTINCT values off the free-text column, not
the scale. Two answers to "what levels does this skill have", neither aware of the
other.

### D4 — Status fields nothing honours

| Field | Reality |
|---|---|
| Skill *Status* (Active/Inactive) | No consumer filters on it. The matrix, framework structure and summary all filter on `approve_status='Approved'` only (`RoleMappingController.php:117`, `StudioController.php:293`) |
| Skill *Skill Status* (Active/Futuristic) | **No reader anywhere.** Written here, and also written by the Gemini JD analyser (`SaveJDController.php:170`). Two writers, zero readers |
| Job Role *Status* | Filterable on this screen only; `StudioController::summary` counts all non-deleted roles regardless (`StudioController.php:198-206`) |

Deactivating a skill does nothing. That is a correctness problem the moment a
customer retires a skill and it keeps appearing in assessments.

### D5 — Silent fallbacks in the tile action rail · `partial`

*Usage insights* and *Linked items* are shown on all eight tabs, but the
`jobroles` and `levels` cards do not exist in `cardsFor()` for Job Role, Job Role
Task and Invisible. The buttons **silently fall back to Details**
(`library-detail-modal.tsx:125-127`). The user clicks a labelled control and gets a
different panel with no explanation.

For those same three tabs the row-click popup fetches nothing and shows a raw field
dump — the associations endpoint that returns tasks, mapped skills and
`s_library_map` KASA is never called.

### D6 — Export without import

Export exists on all eight tabs. **Import exists on none.** Truncation past 10,000
rows is disclosed only in a transient banner. A customer cannot load their existing
skill library into this product, which makes onboarding a manual re-typing exercise
— this is why G-FLOW-03 / Q-C1 was elevated.

### D7 — Free-text vocabularies drift by design

`library-form.tsx:54-112` — the OpenChoice *"+ Add a new …"* control writes the new
value as a free string with **no master record**. Combined with the Department
filter reading DISTINCT values off the data itself
(`LibraryController.php:1753-1764`), a single misspelling becomes a **permanent
filter option** that quietly splits the dataset in two.

The Job Role filter has the mirror-image bug: it reads
`meta.jobroles_by_department`, which **drops every role with a blank department**
(`LibraryController.php:1738-1741`). Roles exist that the filter can never show.

### D8 — The responsibility ladder is decorative

`levels-of-responsibility.tsx` renders `s_level_responsibility` in the Job Role
taxonomy drawer. The Job Role form's *Job Level* field is free text placeholdered
`"e.g. L3"` and is **never validated against it**. The ladder is displayed inches
from the field it should populate.

---

## 4. Where the inventory needed correcting

Per R4 and the "flag, do not guess" rule, corrections are reported, not silently absorbed.

**The screen-level note claimed the customer was wrong to say "no relationship
between the tabs".** It is right to push back, and the three relationships it cites
are real — I re-verified all three. But the phrasing *"CUSTOMER CLAIM PARTLY
WRONG"* overstates the rebuttal, and this write-up does not adopt it. The three
relationships are:

| Relationship | Verified at | But |
|---|---|---|
| Job Role Task `.jobrole` is a strict dropdown fed from the Job Role tab | `library-config.ts:255-264`; Task Mgmt reads it at `services/task/index.ts:594-611` | joined on the role **name**, and a task carries **no skill link at all** — so executed work can never feed `s_skill_matrix` |
| Skill tab's Job Role filter joins `s_user_skill_jobrole` | `LibraryController.php:531-540` | the filter's option list drops blank-department roles (D7) |
| KASA detail popup resolves "which skills reference this item" | `LibraryController.php:1064-1092` | read-only; **no form field can create the link** |

So the tabs are related — by **string matching, in one direction, read-only**. The
customer's complaint is substantively correct even though the literal wording is
too strong. **Recorded as a correction to the inventory, not to the customer.**

**Third correction — and the one that matters most.** The Job Role Task row states
*"there is no task→skill column anywhere"* (raw inventory, Job Role Task tab). That
is **wrong**, and I repeated it in L-14 instead of catching it. The raw file is left
unedited as evidence; the correction lives in **§5.2**, and the rule that would have
caught it is **C6b**.

**Second correction.** The row for *Skill Status* says *"Nothing reads `skill_status`
anywhere."* True as stated — but the sweep found a **second writer**
(`SaveJDController.php:170`, the Gemini JD analyser). The claim is not wrong; it is
incomplete, and the omission matters because it means two subsystems populate a
column neither reads.

---

## 5. CONNECTIONS TO BUILD

**This is the C8 fixed format. Every subsequent Gate C write-up uses these exact
columns and this exact ordering rule.**

Ordering: descending `Value ÷ Cost`. `Blocked by` names a gate item or another
connection, never a person. `Evidence` is a file:line that was actually opened.

### Cost tiers

| Tier | Meaning |
|---|---|
| **display** | **No join, no schema, no migration.** Show text that already exists on the screen where the work happens |
| **XS** | One field or one line; no schema change |
| **S** | A typing change plus its form control and read path |
| **M** | A new join table or a new flow |
| **L** | A model change with a migration and a backfill |

### The three rules governing every BIND

Stated once here; every BIND row below obeys them.

| | Rule | Why |
|---|---|---|
| **R-a** | **A field that drives behaviour must stop being free text.** Every BIND carries a typing change — enum, boolean or number | Binding logic to free text is exactly how department-as-text happened (D1). A BIND that leaves the column as `TEXT` has not fixed anything, it has added a consumer to a defect |
| **R-b** | **Bind to the home that already exists in the new schema. Do not create parallel systems** | *Importance* → `competency_kasba_item.weight`. *Risk Implications* → `competency.criticality`. A second weighting or severity system would immediately contradict the first, and nothing would say which wins |
| **R-c** | **Some connections are display-only** — no join, no schema, just showing text on the screen where the work happens | An assessor who cannot see the success criterion is guessing. That is worth fixing and it costs nothing |

| # | Connection | From → To | Why it matters to a buyer | Cost | Blocked by | Evidence |
|---|---|---|---|---|---|---|
| **L-01** | Write `department_id` from the Job Role form | Libraries → HRIT org | Roles created in Competency currently **never appear** in HR's department listing. Column already accepts it | **S–M** ⚠️ *(was XS — see §5.5)* | — | `LibraryController.php:81`; `JobroleApiController.php:27-28` |
| **L-02** | Same for Skill `department_id` | Libraries → HRIT org | Same break on the Skill tab; same already-writable column | **S–M** ⚠️ *(was XS — see §5.5)* | — | `LibraryController.php:59` |
| **L-03R** | **Delete** `library-detail.tsx` and its `Sheet` block — the popup is the richer view and becomes the single one | within Libraries | Removes 366 lines of unreachable UI. **Withdraws the earlier L-03**, which would have created a second parallel view of the same record | **XS** | ⚠️ **needs deletion approval** | §5.4; `library-detail-modal.tsx` 681 lines / 8 cards vs `library-detail.tsx` 366 / 2 tabs |
| **L-21** | Show *Performance Metrics* on the assessor's rating screen | within Competency | The success criterion for the skill being rated. **An assessor currently rates without seeing it.** R-c | **display** | — | `library-config.ts:177` |
| **L-22** | Show *Measurement Metrics* as rating-scale anchor text | within Competency | Same, at ability level — turns a bare 1–5 scale into anchored judgement. R-c | **display** | — | `library-config.ts:323` |
| **L-23** | Show *Development Methods* at development-plan creation | Libraries → Development Plans | A suggestion to a human choosing an intervention. **Never an automated action.** R-c | **display** | — | `library-config.ts:346` |
| **L-15** | *Compliance Relevance* → **BOOLEAN + regulation reference** | Libraries → Assessments, LMS, Compliance report | **Highest-value item on the page.** Flags the item as regulatory → mandatory assignment, expiry tracking, the compliance report. Should also inform the `requires_assessment` default (Q-B2). R-a | **S** | — | `library-config.ts:301`; `02-domain-model.md` §2.1 |
| **L-16** | *Risk Implications* → **SEVERITY ENUM**, feeding `competency.criticality` | Libraries → Competency model | Separates *"safety incident"* from *"development need"* — two things the product currently treats identically. **Binds to the existing field**, no parallel severity system. R-a, R-b | **S** | `competency` table (§10 step 3) | `library-config.ts:372` |
| **L-18** | *Importance* → source for `competency_kasba_item.weight` | Libraries → Competency model | Every skill currently counts equally in gap scoring. **Binds to the existing weight column**, no parallel weighting system. R-a, R-b | **S** | `competency_kasba_item` (§10 step 3) | `library-config.ts:167` |
| **L-19** | *Experience* → **NUMERIC minimum years** | Libraries → Succession, Internal Mobility | Eligibility filter for golden threads 6 and 7. Today a shortlist cannot exclude anyone on experience. R-a | **S** | — | `library-config.ts:224` |
| **L-17** | **SUBSTITUTION** — replace *Cognitive Elements* and *Psychomotor Elements* with ONE `assessment_method` enum on the item (`test` / `observed_demonstration` / `portfolio` / `manager_rating`) | Libraries → Assessments | The real idea underneath both fields. A cognitive ability needs a test; a psychomotor one needs observed demonstration. **Drives assessment design**, which neither text field ever could. R-a | **S** | — | `library-config.ts:321-322` |
| **L-04** | Bind *Job Level* to `s_level_responsibility` | Libraries → Libraries | Turns a decorative ladder into the job architecture. The data is on screen already | **S** | — | `library-config.ts:221`; `taxonomy-manager.tsx:373-383` |
| **L-05** | Honour Status **at assignment time** — retiring a skill blocks *new* assignments and leaves open ones untouched | Libraries → Framework, Assessments | A retired skill keeps being assigned. **Q-L2 answered: do NOT filter at read time** — an in-flight assessment is a measurement being taken, and changing its contents mid-way corrupts the result | **S** | — | `RoleMappingController.php:117`; `StudioController.php:293`; `02-domain-model.md` §11 (iii) |
| **L-06** | Show impact count in the delete dialog; block or cascade | within Libraries | Deleting a skill silently orphans live ratings. The usage query already exists | **S** | — | `library-tab.tsx:1128-1153` |
| **L-20** | The three `*_tags` columns (Ability, Attitude, Behaviour) → real tag entities feeding the shared category table | within Libraries | Cheap, and the only cross-taxonomy grouping mechanism the KASA tabs have. Feeds L-12 directly | **S** | L-12 | `library-config.ts:327,350,374` |
| **L-07** | Replace *Job Titles* free text with `s_user_skill_jobrole` | Libraries → Framework | Removes a **contradictory second answer** to "which roles need this skill" | **M** | L-11 (needs `skill_id`) | `library-config.ts:171` |
| **L-08** | Replace *Learning Resources* free text with LMS course refs | **Competency → LMS** | The single most important cross-module hook is a notes field. Without it a gap can never become an enrolment | **M** | L-11; LMS write-up | `library-config.ts:172` |
| **L-09** | Replace *Certifications* free text with `lms_certificates` refs | Competency → LMS | No expiry tracking, no compliance reporting, without it | **M** | L-08 | `library-config.ts:174` |
| **L-10** | Import, on every tab | outside → Libraries | **No customer can load their existing library.** Onboarding is manual re-typing | **M** | G-FLOW-03 / Q-C1 | `library-tab.tsx:487-528` |
| **L-11** | Join on `skill_id` / `jobrole_id`, not on title | Libraries → everything | The root cause. Until this lands, every rename is a silent data-loss event and L-07/08/09 rest on strings | **L** | Gate B migration sequence (`02-domain-model.md` §10) | `library-config.ts:153,211` |
| **L-12** | **One shared category table, each category declaring which of the 7 taxonomies it applies to** (Q-L3) | within Libraries | Enables *"everything Safety-related across Skill, Knowledge and Behaviour"* without forcing irrelevant categories onto every tab. Today those are unrelated strings that can never be reconciled | **L** | L-11 | `library-tab.tsx:606-614` |
| **L-13** | Propagate taxonomy renames to stored consumers | Libraries → Framework, reports | A rename desynchronises `s_competency_framework_weights.category` and every report keyed on it. Once L-12 lands the category has an **id**, so a rename becomes a label change and this connection shrinks to a back-fill | **M** | L-12 | `taxonomy-manager.tsx:243-254`; `LibraryController.php:1340-1400` |
| **L-14** | **Already approved — do not re-scope.** Bind the task **catalogue** to competency via `jobrole_task_competency_map` | Libraries → Task Mgmt → Competency | The **catalogue** (`s_user_jobrole_task`) has no competency link. The **instance** (`task.skill_id`) does — populated on **1,514 of 2,271 tasks (67%)**, but hand-picked at creation and therefore weak. Today capability can only be inferred from what an individual task creator happened to tick; there is no role-level statement of which competencies a task exercises | **L** | **Q-C3, already answered.** `02-domain-model.md` §2.1 + §10 step 3 | `library-config.ts:231-278`; `services/task/index.ts:594-611`; `manager.md` §1.2; `02-domain-model.md` §3 |

**L-01, L-02 and L-03 are three one-liners that fix a confirmed break and recover a
finished feature.** Confirmed as the first commits of Gate D.

### 5.1 New work versus already-approved work — **mandatory in every write-up**

**Gate D must not count the same work twice.** Every connection is reconciled
against the Gate B decisions before it is proposed. This section exists because
L-14 was originally written as new work when it had already been approved as Q-C3.

| # | Verdict | Gate B item it maps to |
|---|---|---|
| L-01 | **NEW** | — form-side; the backend column already exists |
| L-02 | **NEW** | — as above |
| L-03R | **NEW** | — a deletion, no model implication. Supersedes L-03 |
| L-04 | **NEW** | — `s_level_responsibility` exists; nothing in Gate B binds it |
| L-05 | **NEW** *(shape now fixed by Q-L2)* | consistent with `02-domain-model.md` §11 (iii) — *inactive excludes from new mappings, retains existing* |
| L-06 | **ALREADY APPROVED** | `02-domain-model.md` §11 (iii) — **block the delete, list what uses it**. L-06 is only the **UI surfacing** of a decided rule, not a new decision |
| L-07 | **PARTLY APPROVED** | subsumed by `jobrole_competency_map` (§2.1, step 3). What remains new: **removing the free-text *Job Titles* field** so the two cannot disagree |
| L-08 | **ALREADY APPROVED** | `course_competency_map` (§2.1, step 3). New only in that this screen must **stop offering a free-text substitute** |
| L-09 | **NEW** | certificates are named in the ownership table (§1) but **no certificate↔competency mapping is in the §10 sequence.** Flagged: this may be a genuine Gate B omission rather than new scope |
| L-10 | **ALREADY APPROVED** | §9 import flow, §10 steps 8–9 |
| L-11 | **ALREADY APPROVED** | §10 steps 12 and 14 — normalise the matrix, add `jobrole_id` FK, drop text keys |
| L-12 | **NEW** *(shape now fixed by Q-L3)* | no category table in Gate B |
| L-13 | **NEW** | follows L-12 |
| L-14 | **ALREADY APPROVED** | **Q-C3 → `jobrole_task_competency_map`**, §2.1 + §10 step 3 |
| L-15 | **NEW** | no compliance flag anywhere in Gate B. **Feeds an existing decision** — informs the `requires_assessment` default (Q-B2) |
| L-16 | **BINDS TO EXISTING** | `competency.criticality` (§2.1). **No new severity system** — R-b |
| L-17 | **NEW** | no `assessment_method` on any competency table. Substitution, so it also **removes** two columns |
| L-18 | **BINDS TO EXISTING** | `competency_kasba_item.weight` (§2.1). **No new weighting system** — R-b |
| L-19 | **NEW** | typing change only; no new table |
| L-20 | **FOLLOWS L-12** | not separately scheduled |
| L-21 | **NEW** | display-only; no model implication |
| L-22 | **NEW** | display-only; no model implication |
| L-23 | **NEW** | display-only; no model implication |

**Tally of 23 connections: 13 genuinely new, 5 already approved, 2 bind to an
existing field, 1 partly, 1 follows another, 1 was flagged and is now confirmed
(L-09).** The already-approved rows are not extra Gate D cost — they are this
screen's share of work already scheduled.

**Two of the new rows cost nothing in schema terms** (L-16, L-18): they bind to
`competency.criticality` and `competency_kasba_item.weight`, which §2.1 already
defines. Per **R-b** that is the point — the alternative was two parallel systems
that would immediately contradict each other with nothing to say which wins.

### 5.3 L-09 — resolved, and it uncovered something larger

L-09 was flagged in the first draft as a possible Gate B omission. **Confirmed.**
Two checks were run before designing anything:

| Check | Result |
|---|---|
| What does `s_competency_certification_requirements` express? | **"Certification Y is REQUIRED for role/department/competency X"** — the policy direction only. Its `competency_id` is a **scoping filter**, not an evidence claim, and there is one nullable competency per row with no proficiency level |
| Does the model separate certification TYPE from HELD INSTANCE? | **Half.** The instance is properly instance-shaped (`user_id`, `issued_date`, `expiry_date`, `credential_id`). But **there is no TYPE entity at all** — both the policy and the credential carry their own free-text `name` and `issuing_body` |

**The larger finding is now `G-CERT-01` (S2).** Two employees holding the same
real-world certification are two unrelated strings, so no coverage or expiry
roll-up can be trusted — and the competency mapping has nowhere correct to live.
It is L-09's prerequisite, not a parallel item.

`certification_type` and `certification_competency_map`
(`certification_type_id`, `competency_id`, `proficiency_level`, `is_primary` —
symmetric with `course_competency_map`) are added to the migration sequence as
**steps 3b and 9b**. Design and reasoning: `02-domain-model.md` §10.1.

### 5.2 The correction to L-14, stated plainly

The original wording — *"no task→skill column exists anywhere"* — was **false**,
and it contradicted a finding this project had already verified and built on:

| Layer | Competency link | Evidence |
|---|---|---|
| **Catalogue** — `s_user_jobrole_task` | **absent.** No column in either direction | `library-config.ts:231-278` |
| **Instance** — `task.skill_id` | **present**, populated on **1,514 of 2,271 (67%)** | `manager.md` §1.2 |

So the correct statement is: **the catalogue has no competency link; the instance
has one, and it is hand-picked and weak.** The instance link is why
`02-domain-model.md` §3 could adopt *"catalogue wins, instance is a
confidence-tagged override"* — a rule that is meaningless if no instance link
exists. The claim passed a pure code check and still contradicted a settled
decision, which is what **C6b** now guards against.

**L-11 and L-14 remain the two that decide whether this is a product or a set of
forms** — but L-14's value is now correctly stated: not *creating* the path from
work to competency, but making it a **role-level statement** instead of a per-task
guess by whoever created the ticket.

### 5.4 Acceptance tests — written before the code exists

**L-01, L-02 and L-03 are the first CODE change of Phase 3.** They run through the
`_changes/G-NAV-01-*` template: backup → guard → stated blast radius → exact
rollback. Tests are written now, so "done" is defined before anything is built.

#### Change-template header (all three)

| | |
|---|---|
| **Backup** | None required — **no schema change and no data migration.** L-01/L-02 add a form field that populates an **existing, already-writable** column; L-03 passes an argument to an existing setter. The pre-change `git` SHA is the rollback point |
| **Guard** | `department_id` must be **NULL-able and remain NULL-able.** Existing rows keep NULL; nothing is backfilled by these three commits. A backfill is a separate, reviewed change |
| **Blast radius** | L-01 → Job Role create/edit form + `LibraryController` jobrole write path. L-02 → Skill create/edit form + skill write path. L-03 → `library-tab.tsx` row-click handler only. **No shared component is modified**; no other tab changes behaviour |
| **Rollback** | `git revert` the commit. Rows created in between keep a populated `department_id`, which is **harmless** — the column already existed and no reader requires it to be NULL |
| **G-SEC-05 pre-check** | These touch a tenant-scoped write path. Confirm `sub_institute_id` is still derived from the token, not the request, in the modified handler |

#### AT-L01 — a job role created in Competency appears in HR

| Step | Action | Expected |
|---:|---|---|
| 1 | Sign in as an Admin of tenant A | — |
| 2 | Competency → Libraries & Taxonomy → **Job Role** tab → Create | Form opens with a **Department picker** (not a free-text box) |
| 3 | The Department control | Options come from **`hrms_departments`**, tenant-scoped. Typing a new name is **not** possible on this control |
| 4 | Create role `AT-L01-Role` in department `Engineering`; save | Saves without error |
| 5 | Query `s_user_jobrole` for `AT-L01-Role` | **`department_id` = the `hrms_departments.id` for Engineering.** `department` text still populated (retained, not dropped) |
| 6 | Open **HRIT → Job Roles**, filter department = Engineering | **`AT-L01-Role` is listed.** This is the assertion that fails today |
| 7 | Sign in as an Admin of tenant B, repeat step 6 | **`AT-L01-Role` is NOT visible.** Tenant isolation preserved |
| 8 | Edit an existing role that has NULL `department_id`, change nothing, save | `department_id` stays NULL. **The fix must not silently backfill** |

**Fails today at step 6** — that is the confirmed break.

#### AT-L02 — the same, for skills

| Step | Action | Expected |
|---:|---|---|
| 1 | Skill tab → Create, department = `Engineering` | Department picker, same source as AT-L01 |
| 2 | Query `s_users_skills` for the new row | **`department_id` populated** |
| 3 | Any consumer joining skills to `hrms_departments` | Resolves the new skill |
| 4 | Skill tab → Department **filter** | Still lists existing free-text values **and** the resolved department. **Pre-existing rows must not disappear from the filter** |

Step 4 is the regression that matters: the filter reads DISTINCT free text today
(D7), and legacy rows must keep working while both mechanisms coexist.

#### AT-L03 — the detail panel opens

| Step | Action | Expected |
|---:|---|---|
| 1 | Any library tab → click a table row | **The side panel opens** showing that row |
| 2 | Panel content | `library-detail.tsx` renders; for Skill and Job Role the read-model query at `library-tab.tsx:225` fires with the row's id |
| 3 | Close the panel | `selected` returns to null; no console error |
| 4 | Click a row on **Job Role Task** and **Invisible** | Panel opens. Sections with no data render **empty, not broken** |
| 5 | Click a row, then switch tabs | Panel closes. It must not carry a stale row across tabs |
| 6 | The existing row-click → detail **popup** | **Still works.** L-03 must not replace one route with another — verify both, or state deliberately that the popup is retired |

#### Which is richer — decided, and it reverses L-03

**The popup is richer, decisively.** `library-detail-modal.tsx` is **681 lines** and
offers **eight cards** for a skill — Details, Job Role, Proficiency Level,
Knowledge, Ability, Application, Attitude, Behaviour — plus four for the KASA tabs,
and it **fetches its own association data** (`cardsFor()`, lines 83–108). The panel,
`library-detail.tsx`, is **366 lines** with **two tabs** (Overview, Associations),
it renders associations for **only** `skill` and `jobrole`, and it does not fetch —
`detail` and `detailLoading` arrive as props. Everything the panel shows, the popup
shows in more detail and for more tabs.

Under the standing rule — *the richer view becomes the single detail view* — **the
popup wins, so L-03 is not worth doing.** Recovering 366 lines of dead UI is not a
reason to create a second parallel view of the same record; the reason it looked
attractive was the sunk cost of the code, which is not a reason at all.

**L-03 is therefore withdrawn and replaced by L-03R: delete `library-detail.tsx`
and the `Sheet` block at `library-tab.tsx:1054-1073`.** ⚠️ **Deletion requires
explicit approval** and is proposed, not scheduled. AT-L03 above is superseded by
AT-L03R.

#### AT-L03R — removing the panel changes nothing a user can see

| Step | Action | Expected |
|---:|---|---|
| 1 | Before the change, click a row on **every one of the 8 tabs** | The **popup** opens each time. Record what each shows |
| 2 | Confirm the panel is genuinely unreachable | No interaction on any tab opens the `Sheet`. This is the premise; if any path opens it, **stop** |
| 3 | Remove `library-detail.tsx`, the `Sheet` block, the `selected` state and the now-unused hook at `library-tab.tsx:225` | Build passes with no unused-import or dead-code warnings |
| 4 | Repeat step 1 | **Identical behaviour on all 8 tabs.** A pure deletion must be observably inert |
| 5 | Check the backend read model the panel would have used | If the popup also uses it, **keep it.** If nothing else calls it, report it separately — do **not** delete backend endpoints in the same commit |

Step 5 matters: the panel and the popup may share the read model, and a deletion
commit must not quietly remove a working endpoint. **Frontend deletion only.**

### 5.5 ⚠️ COST CORRECTION — L-01 and L-02 are not XS

**Found while starting the build, before writing any code.** The XS estimate was
wrong and the error was mine.

XS assumed the form merely had to *send* a column the backend already accepts.
`LibraryController.php:81` does accept `department_id`, so that part held. But
AT-L01 step 3 requires the control to be **an id-bearing picker sourced from
`hrms_departments`, with new names not typeable** — and the entire options pipeline
is **string-only by construction**:

| Layer | Evidence | Why it blocks |
|---|---|---|
| Meta endpoint | `LibraryController.php:1745-1775` | The options query is a `UNION` of `(bucket, value)` pairs selecting **`department` free text from `s_users_skills`** — a single `value` column that **structurally cannot carry an id**, and it never reads `hrms_departments` at all |
| `sourceValues()` | `library-form.tsx:115` | returns `string[]` |
| `OpenChoice` | `library-form.tsx:54-80` | takes `options: string[]`, `onChange: (value: string) => void`. It is an **open** choice by design — "a genuinely new department must still be typeable" is a stated intent in the config comment |

So L-01/L-02 need: a new id-bearing meta bucket reading `hrms_departments`
(tenant-scoped), a `LibraryMeta` type change, a **closed-list** control that is not
`OpenChoice`, and payload mapping — across **three files and both repos**, twice.

**Revised: S–M each.** Still high value and still early, but **they are not
one-liners and must not be scheduled as such.**

**L-03R is unaffected — genuinely XS**, a deletion with no dependencies.

**Two consequences worth stating:**
1. The "three one-liners" framing of the first Gate D commits was **wrong**. One of the three is; two are not.
2. This is D7 in disguise. The open-choice pipeline *is* the mechanism by which vocabularies drift, so binding department to an id means **replacing** that control for this field, not configuring it. That is a design decision, not a fix — and it is the same decision L-11 will force everywhere.

### Deliberately NOT proposed

| Not building | Why |
|---|---|
| Consumers for the 24 orphan text fields | They are genuine free-text notes (*Coaching Guidelines*, *Common Challenges*). Not every field needs a join. **Flagged for the owner to confirm** — if any were meant to drive behaviour, that changes its row above |
| A new detail panel | One already exists and works (L-03). Rebuilding it would be the expensive way to fix a one-line bug |
| Removing the duplicate skill writer (`cm-competency-library`) | Duplication **D2** from Gate A. Belongs to that write-up, not this one |

---

## 6. Questions

**Q-L2 — `ANSWERED`. Persist until the cycle closes; filter at ASSIGNMENT time.**
Retiring a skill blocks new assignments and leaves open ones untouched. An in-flight
assessment is a measurement being taken, and changing its contents mid-way corrupts
the result — the same principle as the block-don't-cascade soft-delete rule
(`02-domain-model.md` §11 (iii)): housekeeping must not rewrite measurement history.
**L-05 adjusted**, and its blocker removed — the question it was waiting on is now
settled.

**Q-L3 — `ANSWERED`. One shared category table with per-taxonomy applicability.**
A single table; each category declares which of the seven taxonomies it applies to.
Cross-taxonomy reporting (*"everything Safety-related across Skill, Knowledge and
Behaviour"*) becomes possible without forcing irrelevant categories onto every tab.
**L-12 and L-13 stay in scope, built to that shape.**

### Q-L1 — `ANSWERED` 2026-08-06

**Marked: BIND = 10, NOTE = 13, SUBSTITUTE = 2 (one substitution).** The BINDs are
folded into §5 as L-15 through L-23, each carrying its typing change per **R-a**,
and each reconciled in §5.1. The NOTEs stay as notes and are **not** gaps — that
resolution removes them from the gap count rather than adding work.

Three judgements worth recording because they prevent future duplication:

- **Theoretical Foundation → NOTE.** Prerequisite chains are a real LMS feature but belong to learning paths. **Do not start a second prerequisite mechanism.**
- **Improvement Strategies → NOTE**, because it overlaps *Development Methods* (L-23). Bind one, not two.
- **Cultural Alignment → NOTE with a trigger:** it becomes a BIND **the day a company-values entity exists.** Recorded so it is not silently lost.
- **Business Link / Reference Link → NOTE**, for the same reason: no business-outcome entity exists to join to. Revisit if one is introduced.

#### A correction to my own verification coverage

The sweep in §0 covered **24 column names**, but this table has **25 rows**. The
Skill tab's *Business Link* writes the **misspelt** column `bussiness_links`, while
the KASA *Reference Link* writes `business_link` — two different columns that I
swept as one.

`bussiness_links` re-checked separately. It is **not** the clean zero the other 23
were: `skillLibraryController.php` (the legacy Blade screen) and
`SchoolSetupController.php:232,264` both select and copy it. **All are select-and-copy
paths, none drives behaviour**, so the NOTE marking stands — but the field is not
strictly unread, and saying so would have been wrong. Caught by reconciling two
counts that should have matched (**R1**).

#### The marking, for the record

**One pass, one mark per row.** Default is **NOTE** — notes are notes, and nothing
happens if you skip a row.

Mark **BIND** only where the field is meant to *drive* something. `what it would
drive if bound` is my reading, not a recommendation.

**A correction to the framing first:** the 24 verified orphans and L-07/08/09 are
**disjoint sets**, so 24 remain here, not 21. *Job Titles*, *Learning Resources* and
*Certifications* are separate fields that were never in the no-consumer group — they
have consumers in the sense that a human reads them; they simply have no join. All
24 below have **no reader at all**.

#### Skill tab (3)

| Field | Column | What it would drive if bound | **Marked** |
|---|---|---|---|
| Importance | `skill_importance` | Weighting in the competency framework — currently every skill counts equally in gap scoring || **BIND** → L-18 |
| Performance Metrics | `performance_metrics` | The success criterion an assessor reads when rating this skill || **BIND** → L-21 (display) |
| Business Link | `bussiness_links` *(misspelt)* | Which business outcome the skill serves — the spine of any "capability → business impact" report || NOTE |

#### Job Role tab (1)

| Field | Column | What it would drive if bound | **Marked** |
|---|---|---|---|
| Experience | `experience` | Minimum years for the role — an eligibility rule for succession and internal mobility || **BIND** → L-19 |

#### KASA shared (1)

| Field | Column | What it would drive if bound | **Marked** |
|---|---|---|---|
| Reference Link | `business_link` | Same as above, at KASBA-item level || NOTE |

#### Knowledge (3)

| Field | Column | What it would drive if bound | **Marked** |
|---|---|---|---|
| Theoretical Foundation | `theoretical_foundation` | Prerequisite chain — what must be learned before this || NOTE |
| References | `references` | Source material an LMS course could be generated from || NOTE |
| Compliance Relevance | `compliance_relevance` | **Flags the item as regulatory** → mandatory assignment, expiry tracking, audit report. The highest-value candidate on this page || **BIND** → L-15 |

#### Ability (5)

| Field | Column | What it would drive if bound | **Marked** |
|---|---|---|---|
| Cognitive Elements | `cognitive_elements` | Assessment *method* selection — cognitive abilities need tests, not observation || **SUBSTITUTE** → L-17 |
| Psychomotor Elements | `psychomotor_elements` | Same, inverted: needs observed demonstration, not a quiz || **SUBSTITUTE** → L-17 |
| Measurement Metrics | `measurement_metrics` | The rating scale's anchor text for this ability || **BIND** → L-22 (display) |
| Common Challenges | `common_challenges` | Coaching content shown when someone scores low || NOTE |
| Tags | `ability_tags` | Search and cross-taxonomy grouping — would feed L-12 || **BIND** → L-20 |

#### Attitude (4)

| Field | Column | What it would drive if bound | **Marked** |
|---|---|---|---|
| Development Methods | `development_methods` | The suggested intervention when a gap is found — a direct development-plan input || **BIND** → L-23 (display) |
| Improvement Strategies | `improvement_strategies` | As above, at action level || NOTE |
| Cultural Alignment | `cultural_alignment` | Ties the attitude to a stated company value — what makes this saleable to HR || NOTE *(trigger)* |
| Tags | `attitude_tags` | Search / L-12 || **BIND** → L-20 |

#### Behaviour (4)

| Field | Column | What it would drive if bound | **Marked** |
|---|---|---|---|
| Alternative Behaviours | `behaviour_alternatives` | "Do this instead" guidance in feedback || NOTE |
| Risk Implications | `risk_implications` | **Severity weighting** — some behavioural gaps are safety incidents, not development needs || **BIND** → L-16 |
| Coaching Guidelines | `coaching_guidelines` | Manager-facing script in the 1:1 flow || NOTE |
| Tags | `behaviour_tags` | Search / L-12 || **BIND** → L-20 |

#### Invisible (4)

| Field | Column | What it would drive if bound | **Marked** |
|---|---|---|---|
| Purpose | `purpose` | Why the item exists || NOTE |
| Benefits | `benefits` | — || NOTE |
| Limitations | `limitations` | — || NOTE |
| Example Use Case | `example_use_case` | — || NOTE |

**My reading, offered so you can disagree in one word:** the Invisible four are
genuine notes. Two stand out as probably BIND — **Compliance Relevance**
(regulatory tracking is a compliance product feature customers pay for) and **Risk
Implications** (a safety-critical behavioural gap is not the same as a development
need). The three `*_tags` columns are cheap and would feed L-12. The rest read as
authoring guidance.

---

## 7. Status

`Analysis Done`. No code changed. 176 rows examined, 0 structural errors,
29 of 29 hand-verified negative claims TRUE against the code, 2 corrections to the
inventory in §4 — **and one correction to my own work in §5.2**, where L-14 passed
the code check and still contradicted a verified Gate B finding. That miss is why
**C6b** now exists and why §5.1 is mandatory from here on.

Q-L2 and Q-L3 answered and folded into L-05, L-12 and L-13. **Q-L1 open**, reduced
to a one-pass tick list in §6.

Next in C2 order: **Competency Library** (`cm-competency-library.tsx`, 77 rows) —
which writes the same `s_users_skills` table through a different endpoint, and so
inherits D2 from Gate A.
