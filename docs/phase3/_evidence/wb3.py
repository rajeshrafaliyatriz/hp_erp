import io, os, re
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

# ---------- 1. the per-profile correction, recorded beside R19 ----------
p = os.path.join(D, "07-gap-register.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("| **R17** |",
"""| **R19b** | **AN AGGREGATE IS NOT AN EXPERIENCE.** A total summed across tenants, users or profiles must never be quoted as if it described one of them. State the per-unit figure, or say explicitly that the number is an aggregate | **G-SEC-07.** *"Employee sees 1,657 menus vs Admin 1,500"* was summed across **11 tenants**. What **one user** sees is **151 vs 136**. Same family as R19: a figure assembled from parts and then read as something it never measured. **The inversion survives and remains the finding** |
| **R17** |""")

# correct G-SEC-07's own figures wherever they appear
t = t.replace("`can_view` = 1 on **all 4,879 rows**; every other action\ncolumn = 0; Employee sees **1,657** menus against Admin's **1,500**.",
"""`can_view` = 1 on **all 4,879 rows**; every other action column = 0.

⚠️ **CORRECTED 2026-08-07 (R19b): the menu figures were aggregates across 11
tenants.** Per profile — what **one user** actually sees:

| Role | Per profile | *(old aggregate)* |
|---|---:|---:|
| Employee | **151** | ~~1,657~~ |
| HR | **150** | ~~1,650~~ |
| **Admin** | **136** | ~~1,500~~ |

**The inversion is real and survives the correction** — an Admin sees fewer screens
than an Employee. **Use the per-profile numbers.**""")
io.open(p, "w", encoding="utf-8").write(t)

# ---------- 2. same correction everywhere it is quoted ----------
for name in ("08-connection-plan.md", "06-feature-audit/organization.md", "13-current-state.md"):
    p = os.path.join(D, name)
    if not os.path.exists(p):
        continue
    t = io.open(p, encoding="utf-8").read()
    before = t
    t = t.replace("Employee sees **1,657**\nmenus against Admin's **1,500**",
                  "**per profile**, an Employee sees **151** menus against an Admin's **136**\n(the 1,657/1,500 figures were aggregates across 11 tenants — R19b)")
    t = t.replace("Employee sees\n**1,657** menus against Admin's **1,500**",
                  "**per profile**, an Employee sees **151** menus against an Admin's **136**\n(the old 1,657/1,500 were aggregates across 11 tenants — R19b)")
    t = t.replace("Employee **1,657** menus vs Admin **1,500**",
                  "Employee **151** vs Admin **136** per profile (R19b)")
    t = t.replace("**Employee sees 1,657 menus against Admin's 1,500**",
                  "**per profile, an Employee sees 151 menus against an Admin's 136** (R19b)")
    t = t.replace("Employee sees 1,657 menus vs Admin 1,500",
                  "an Employee sees 151 menus vs an Admin's 136, per profile (R19b)")
    if t != before:
        io.open(p, "w", encoding="utf-8").write(t)
        print("corrected figures in", name)

# ---------- 3. implementation log ----------
p = os.path.join(D, "09-implementation-log.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("\n---\n\n## R7 applied retroactively", """
---

## D-010 · Item 5 — reporting line, role keys, cycle validation

**2026-08-07** · **APPLIED** · commit **`f293edb0`**

| | |
|---|---|
| **Changed** | `tbluser.reporting_manager_id` · `hrms_departments.head_user_id` · `tbluserprofilemaster.role_key` / `data_scope` / `is_system` · new `tenant_setting` table |
| **Files** | `database/migrations/2026_08_07_120000_add_reporting_line_and_role_keys.php` · `app/Services/Org/ReportingLineValidator.php` |
| **Verification** | 6 of 6 columns added · `tenant_setting` created · **0 of 387 users have a manager** (all NULL, as expected) · **0 cycles in existing data** |
| **Acceptance** | Validator behaviour tested: **self-reference rejected · NULL manager allowed · clean assignment allowed · 0 existing cycles · default depth 1**. **Not `Verified`** — no UI path exercised |

### The guarantee the schema cannot make

MySQL has **no recursive CHECK**, so "this reporting graph has no cycles" cannot be
a constraint. It lives in `ReportingLineValidator`, and **the migration header says
so** rather than letting a later reader assume the schema enforces it:

- **`canAssign()`** walks **up** from the proposed manager and refuses if it reaches the user. Rejects self-reference (the degenerate one-node cycle). **Refuses to extend a pre-existing cycle** rather than silently absorbing it.
- **`teamOf()`** is bounded by `team_scope_depth` (A5, default **1 = direct reports only**) and carries a seen-set, so **even a corrupt graph terminates**.
- **`findCycles()`** for the periodic check — same shape as the polymorphic-integrity check for `competency_kasba_item`.

### `role_key` — why it exists

The resolver keys on `role_key`, **never on `name`**. Renaming a role in a
customer's UI must not break access. `data_scope` lives on the role because **scope
is never individually overridable** (A6).

### Ordered before 4b deliberately

§3.1–3.7 is written against **nine** roles; three exist. This migration creates the
model that matrix needs, so 4b can apply it faithfully rather than collapsing nine
columns into three.

---

## R7 applied retroactively""")
io.open(p, "w", encoding="utf-8").write(t)

# ---------- 4. current state ----------
p = os.path.join(D, "13-current-state.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("| 10 | `reporting_manager_id` + `head_user_id` | **Not started** |",
              "| 10 | **`reporting_manager_id` + `head_user_id` + role_key + cycle validation** | ✅ **APPLIED** (`f293edb0`) — 6 columns, `tenant_setting`, validator tested |")
t = t.replace("**Built: 9.**\n\n**FOUNDATIONS BUILT — 2 of 6.**",
              "**Built: 10.**\n\n**FOUNDATIONS BUILT — 3 of 6, with item 4 at 4a.**")
t = t.replace("| Rights matrix population (9b) | ~~tri-state columns~~ ✅ — **now blocked on the ROLE MODEL** (item 5) and a screen→menu mapping |",
              "| Rights matrix population (4b) | ~~tri-state columns~~ ✅ · ~~role model~~ ✅ **(f293edb0)** — **now blocked only on 4b-prep**: the nine roles seeded + the screen→menu mapping |")
io.open(p, "w", encoding="utf-8").write(t)

# ---------- 5. progress ----------
p = os.path.join(D, "00-progress.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("**FOUNDATIONS BUILT — 2 of 6.**", "**FOUNDATIONS BUILT — 3 of 6, with item 4 at 4a.**")
t = t.replace("| 5 | `reporting_manager_id` + `head_user_id` + cycle validation | **NEXT** — recommended ahead of 4b, it creates the role model 4b needs |",
              "| 5 | `reporting_manager_id` + `head_user_id` + cycle validation | ✅ done (`f293edb0`) |\n| 4b-prep | **Nine roles + `role_key` + the screen→menu mapping**, for review | **NEXT** |")
io.open(p, "w", encoding="utf-8").write(t)
print("documents written")
