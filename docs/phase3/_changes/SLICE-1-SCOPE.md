# Slice 1 — scope before build

**Answer first. Nothing has been built.**

**Nothing in Slice 1 is blocked by the held orphans.** They live in the old
string-joined tables; Slice 1 writes only to the new key-based ones. A clean role
with clean competencies never touches them. *(Verified, not assumed: the create
path targets `jobrole_competency_map`, which has no text key at all.)*

---

## What already exists

| | Exists | State |
|---|---|---|
| `jobrole_competency_map` | ✅ | `jobrole_id`, `competency_id`, `required_proficiency`, `is_mandatory`, tenant, `created_by`. **0 rows. Nothing in `app/` writes to it** |
| `competency` | ✅ | code, name, type, criticality, `requires_assessment`, status, version. **0 rows** |
| `competency_kasba_item` | ✅ | `competency_id`, `kasba_type` enum(skill/knowledge/ability/attitude/behaviour), `item_id`, `weight`. **0 rows** |
| `cm-framework-mapping.tsx` | ✅ | The screen for menu 154 exists — but writes nothing to the new table |
| `cm-competency-library.tsx` | ✅ | Library screen exists |
| **Rating storage** | ❌ | **No table stores a numeric rating per KASBA item per employee.** `s_skill_matrix` holds `knowledge`/`ability`/`behaviour`/`attitude` as **free TEXT**, 169 rows |
| **Roll-up service** | ❌ | `app/Services/Competency/` contains only `CommandCenterService.php` |

**All five foundation tables are in place and empty. All five behaviours are missing.**

---

## ⛔ TWO THINGS TO SETTLE BEFORE BUILDING

### A. `competency_kasba_item.item_id` is **NOT NULL**, and there is no `item_label`

D-007's record states *"item_label kept + item_id nullable"*. **The built schema
has neither**: `item_id` is `NOT NULL` and no `item_label` column exists.

**Verified against the created schema, not the migration source** — the same rule
that caught the JSON/LONGTEXT note.

**Consequence:** every KASBA item must point at an existing row in some items
table. **Which table?** `skill` plausibly means `s_users_skills`, but
*knowledge*, *ability*, *attitude* and *behaviour* have no obvious canonical
table. **Until that is answered, a competency cannot be composed** — this is the
one genuine blocker in Slice 1.

**Options:** (a) make `item_id` nullable and add `item_label` as D-007 intended —
a small migration, and it matches Q-A2's "named bundle"; (b) name a canonical
table per KASBA type; (c) restrict Slice 1 to `kasba_type='skill'` only, which
ships but does not prove Q-A2.

### B. Does Slice 1 include a competency create/edit screen?

Your item 1 implies competencies already exist; item 2 implies building them.
**The difference is M vs L.** Costed both ways below.

---

## The build list, with R7 costs and named files

| # | Item | Cost | Files |
|---:|---|:-:|---|
| **0** | **Resolve blocker A** — migration making `item_id` nullable + `item_label`, if option (a) | **S** | `database/migrations/*_fix_kasba_item_label.php` |
| **1** | **Competency CRUD API** — create/edit a competency with its KASBA items and weights, tenant-scoped, HR/Admin only | **M** | `app/Http/Controllers/Api/Competency/CompetencyCrudController.php` *(exists — extend)*, `routes/api.php` |
| **2** | **Competency create/edit UI** *(only if B = yes)* | **M** | `g2gv0/components/domain/competency/cm-competency-library.tsx` |
| **3** | **Mapping API** — bulk upsert into `jobrole_competency_map`, modelled on `masterSetupController`'s `insert($insertData)` pattern (M-03), tenant + `created_by` from the token | **M** | `app/Http/Controllers/Api/Competency/RoleMappingController.php`, `routes/api.php` |
| **4** | **Mapping UI + G-MAP-01 fix** — the create path, and rewire the button that currently carries `kind:'framework'` | **M** | `cm-framework-mapping.tsx`, `cm-command-center.tsx:52-58` |
| **5** | **Rating storage** — `competency_kasba_rating` (employee, item, rating, assessor, tenant). **Genuinely new; nothing stores this today** | **M** | `database/migrations/*_create_competency_kasba_rating.php` |
| **6** | **`ProficiencyService`** — the ONE named service doing the Q-E2 weighted roll-up. **No screen does its own arithmetic** | **M** | `app/Services/Competency/ProficiencyService.php` |
| **7** | **Gap API** — required minus measured **by key**, returning **two numbers**: roll-up level, and mandatory items below required. **Unmeasured reports as UNMEASURED — not zero, not pass** — and feeds the coverage gate | **M** | `app/Services/Competency/GapCalculator.php`, controller + route |
| **8** | **Employee's own gap view** — menu 156 is re-granted, so an employee can see it | **S** | `cm-employee-profiles.tsx` |
| **9** | **The rename proof** — rename the job role, show the mapping survives. Subsumes C32 | **S** | `docs/phase3/_evidence/slice1-rename-proof.php` |

**Total: 8 M + 3 S with the competency UI · 7 M + 3 S without.**

### R20 — the chain for what this builds on

- **Create path** → `RoleMappingController` → `routes/api.php` → **must carry `profile:admin,hr`** (`RequireProfile`, now exact `role_key` matching after G-AUTH-02).
- **Employee gap view** → menu 156 → re-granted in 4b → `EmployeeCompetencyProfileController`, which since G-COMP-SEC-01 resolves `$id` against the caller on all 13 methods.
- **Tenant** on every write from `resolveApiIdentity()`, never a request field.

---

## What I recommend

**Answer A first — it is the only real blocker.** I lean to option (a): make
`item_id` nullable and add `item_label`, because Q-A2 describes a *named bundle*
and D-007 recorded that intent. It is an **S**, and without it item 1 cannot store
a knowledge or attitude item at all.

**On B: include the competency UI.** Without it, "a person can do this in the
product end to end" is false — competencies would have to be seeded by SQL, which
is exactly what this slice is meant to stop being necessary.
