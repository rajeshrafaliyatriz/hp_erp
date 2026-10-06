# THE KASBA CREATION FLOW, MEASURED END TO END

**MEASURE TURN — nothing built, nothing changed.** Every row below is a file and
line that was read, or a count that was run. No claim here rests on the plan.

---

## THE TABLE

| Form | Endpoint | Table written | Dropdowns | Options come from | Tenant-scoped | KASBA-connected |
|---|---|---|---|---|---|---|
| **Knowledge** | `POST /competency/library/kasa/knowledge` | `s_user_knowledge` | Category, Sub Category | `s_taxonomy` per tenant | ✅ | ❌ |
| **Ability** | `POST /competency/library/kasa/ability` | `s_user_ability` | Category, Sub Category | same | ✅ | ❌ |
| **Attitude** | `POST /competency/library/kasa/attitude` | `s_user_attitude` | Category, Sub Category | same | ✅ | ❌ |
| **Behaviour** | `POST /competency/library/kasa/behaviour` | `s_user_behaviour` | Category, Sub Category | same | ✅ | ❌ |
| **Skill** | `POST /competency/library/skills` | `s_users_skills` | Category, Sub Category, Status, Skill Status | taxonomy + hardcoded enums | ✅ | ❌ |
| **Job Role** | `POST /competency/library/jobroles` | `s_user_jobrole` | Role Category, Status | taxonomy + hardcoded | ✅ | ❌ |
| **Framework** | `POST /competency/frameworks` | `s_competency_frameworks` + `s_competency_framework_items` | — | — | ✅ | ❌ **does not reach `jobrole_competency_map`** |
| **Matrix cell** | `PUT /competency/role-mapping/cell` | `s_user_skill_jobrole` | roles / skills | **matched by TEXT, not id** | ✅ | ❌ |
| **Course** | `POST /lms/courses` | `sub_std_map` | Department | **`hrms_departments`** (1,181 rows) | ✅ | ❌ **`course_competency_map` untouched** |
| **Task assign** | `POST` legacy task (FormData) | `tasks` (`task.skill_id`) | Skills | `/user-skills/{userId}` — **the employee's OWN skills** | per-user | ⚠ hand-picked `skill_id` only |
| **Quick-create "Competency"** | `POST /competency/competencies` | **`s_users_skills` — a FLAT SKILL ROW** | — | — | ✅ | ❌ |

**Sources**: `services/competency/libraries.ts:77-85` (path map) ·
`app/Http/Controllers/Api/Competency/LibraryController.php:48-171` (table map),
`:610-652` (`storeResource`) · `FrameworkController.php:80` ·
`RoleMappingController.php:202-232` · `LmsCourseController.php:654,714` ·
`services/task/index.ts:479-491` · `SkillLibraryCrudController.php:112`

---

## THE POPULATIONS

    s_user_knowledge         6,950      tenant 7:    74   tenant 3:  2,256
    s_user_ability           6,175                   74              2,498
    s_user_attitude            655                   74                 20
    s_user_behaviour           694                   74                 41
    s_users_skills           5,171                  139                551
    s_user_jobrole           4,736                  120                347
    s_user_jobrole_task     85,663                2,817              7,203
    s_user_skill_jobrole    79,295                2,091              6,460
    s_library_map            3,323                  118                330

    jobrole_competency_map          23 rows
    course_competency_map           56 rows
    competency                     209 rows
    competency_kasba_item          226 rows
    jobrole_task_competency_map      0 rows
    hrms_departments             1,181 rows

---

## THE ANSWERS

### (a) Can K/A/B/A be linked to a job role or department? **NO — and it is MISSING, not by design.**

The four tabs share one field set, `kasaFields()` at
`library-config.ts:107-119`: **title, category, sub_category, description,
assessment_method, business_link**, plus per-tab textareas. **There is no jobrole
field, no department field, and no competency field on any of the four.**

`storeResource()` writes **one row into one table and nothing else** — no map row
of any kind (`LibraryController.php:636`).

**But the link table exists and is populated.** `s_library_map` holds **3,323
rows** with `knowledge_ids` / `ability_ids` / `attitude_ids` / `behaviour_ids`
per subject, and the role detail panel already reads it
(`libraryMapAttributes()`, `:879-895`).

**THE READ PATH EXISTS. THE WRITE PATH DOES NOT.** The only writer of
`s_library_map` in the entire application is **`SaveJDController.php:544`** — the
**Gemini JD parser**. A human using the library forms cannot create the link that
the library screens already display.

### (b) Are department / job-role dropdowns per-tenant? **Tenant-scoped, yes. Sourced from free text, so empty for a new tenant.**

`LibraryController.php:1777-1800`. Every branch is
`WHERE sub_institute_id = ?` — **tenant-scoped correctly.** But the source is:

    UNION SELECT 'department', department FROM s_users_skills WHERE sub_institute_id = ?

**`SELECT DISTINCT` over a free-text column of rows that already exist.** So the
department dropdown offers only departments somebody has already typed into a
skill row in that tenant. **A tenant with no rows gets an empty dropdown, and
there is no way to fill it from this screen** — you cannot pick a department until
a row already has one.

**AND A REAL DEPARTMENT TABLE EXISTS.** `hrms_departments`, **1,181 rows** —
**used by LMS course creation** (`LmsCourseController.php:654` validates against
it) and **ignored by every competency library form.** Two department sources in
one product; the competency side picked the weaker one.

### (c) Framework creation — what does it write? **`s_competency_frameworks` + `s_competency_framework_items`. It never reaches `jobrole_competency_map`.**

`FrameworkController.php:80` and `:378`. Neither the framework form nor the
matrix touches the map.

**`jobrole_competency_map` (23 rows) has exactly ONE writer**:
`RoleCompetencyMapController.php:178`, reachable only through
`POST /competency/role-map` — which the frontend calls **only from the Command
Center quick-create** (`command-center.ts:153`). **The Framework screen, the
matrix and the library cannot write it.**

The matrix writes `s_user_skill_jobrole` (79,295 rows) instead, **keyed by
TEXT**: `saveCell` sends `jobrole` and `skill` as strings (`studio.ts:359-366`).

### (d) Course creation — does it reach `course_competency_map`? **NO. And nothing does.**

`LmsCourseController@store` writes **`sub_std_map`** and nothing else
(`:714`). **`course_competency_map` (56 rows) HAS NO WRITER ANYWHERE IN THE
APPLICATION** — grep across all of `app/` finds only two readers,
`LearningAssigner.php` and `RemediationRecommender.php`. The 56 rows arrived by
provisioning. **Both consumers read a table the product cannot fill.**

### (e) Task assignment — what carries the KASBA link? **A hand-picked `skill_id`, and the picker is backwards.**

`services/task/index.ts:491` — `body.append('skill_id', payload.skillIds.join(','))`.

The options come from **`getUserSkills(userId)` → `/user-skills/{userId}`**
(`:479-482`) — **the skills the EMPLOYEE ALREADY HAS.**

**So a manager can only tag a task with a capability the employee has already been
credited with.** A task that exercises a capability the employee lacks — the only
kind that generates a gap signal — **cannot be tagged at all.** The catalogue map
(`jobrole_task_competency_map`) is **0 rows** and is not consulted.

**Does the screen show the employee which capability the task exercises?** The
assignment payload carries `skill_id` and `skills` (names) — so the tag is
stored, but it is chosen from the employee's existing set, which means **it can
never tell the employee something they did not already know.**

### (f) Is "Create Competency" still writing a flat skill row? **YES, and the code says so.**

`services/competency/command-center.ts:147-151`, in the source, unprompted:

    // ⚠ '/competency/competencies' writes a flat SKILL row into s_users_skills
    // (G-RBAC-02b). A competency in Q-A2's sense is created through
    // services/competency/definitions.ts, not here.

Backend confirms — `SkillLibraryCrudController.php:23` and `:112`,
`DB::table('s_users_skills')->insertGetId(...)`.

**The endpoint was renamed to `/competency/competencies`. The screen behind it
still creates a skill.** The `competency` table (209 rows) is written by a
different path entirely. **This is the rename-without-the-move that the register
already warned about, still live in the UI.**

---

## MISSING vs EXISTS-AND-NOT-WIRED

### GENUINELY MISSING — no code, no table path

1. **A write path for `s_library_map` from the K/A/B/A forms.** The forms have no
   field; `storeResource` has no branch. Only the JD parser writes it.
2. **Any writer at all for `course_competency_map`.** Two readers, zero writers.
3. **Any writer for `jobrole_task_competency_map`.** 0 rows, no writer, and the
   task screen does not consult it.
4. **A task-side capability picker.** The only picker offers the employee's
   existing skills; nothing offers the task's required capability.

### EXISTS AND IS NOT WIRED — the code is there, nothing calls it

5. **`s_library_map` read path.** `libraryMapAttributes()` resolves and renders
   K/A/B/A per job role, against 3,323 real rows. **Fully built, fed only by the
   AI parser.**
6. **`jobrole_competency_map` writer.** `RoleCompetencyMapController@store` works
   and is guarded (`profile:admin,hr`). **Reachable from one quick-create menu and
   from nowhere in the Framework or matrix screens** — the two places a user would
   look.
7. **`hrms_departments`, 1,181 rows.** Used by LMS. **Ignored by every competency
   form**, which prefers `SELECT DISTINCT` over free text.
8. **`competency` / `competency_kasba_item`** — 209 and 226 rows, the KASBA model
   proper. **The Command Center's "Create Competency" does not write them.**

### THE SHAPE

**Six of the eight items above are wiring, not construction.** The tables exist,
the rows exist, the read paths exist and render. **What is missing is almost
entirely the write side of links that the product already displays.**

**And the one consistent cause**: every creation form writes **its own table and
only its own table**. No form in the KASBA flow writes a second row into a link
table. The maps are populated by provisioning and by one AI parser — **never by a
user.**
