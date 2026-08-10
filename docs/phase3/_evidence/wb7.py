import io, os, re
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"
p = os.path.join(D, "07-gap-register.md")
t = io.open(p, encoding="utf-8").read()

# ---- R10c: intent is not behaviour ----
t = t.replace("| **R10b** |",
"""| **R10c** | **INTENT IS NOT BEHAVIOUR.** A comment, a `$description`, a commit message or a design note explains what someone MEANT. Only the running code says what HAPPENS. Never let the first stand for the second | **G-SEC-07, second correction.** `SeedG2gDefaultViewRights`' description says the uniform `can_view=1` is a placeholder *"until an admin curates real rights"* — **true about intent, and it was never replaced.** The product ships on the placeholder. **The same proxy error this project has now caught six times**, one level up: not a checker measuring a proxy, but a *document* standing in for a behaviour |
| **R10b** |""")

# ---- G-SEC-07 THIRD AND FINAL STATE ----
start = t.index("# ⭐ G-SEC-07 — **SUBSTANTIALLY CORRECTED 2026-08-07**")
end = t.index("# G-DUP-01 — TWO PARALLEL RIGHTS SYSTEMS")
FINAL = """# ⭐ G-SEC-07 — THE PRODUCT HAS NO WORKING PERMISSIONS · **S1** · THIRD AND FINAL STATE

> ## THE FINDING, worded so it can be repeated verbatim
>
> **The Next.js product's sidebar reads `tblgroupwise_rights_g2g` via
> `tblmenumasterG2gController::displaySidebarMenu`, filtered by `profile_id`, with
> absence denying (`?? 0`). `can_view = 1` on all 4,879 rows and
> `can_add` / `can_edit` / `can_delete` = 0 on all of them. Every profile sees the
> same 157 menus and no profile holds any action right.**
>
> **THE PRODUCT BEING SOLD HAS NO WORKING PERMISSIONS; roles differ in name only.**
>
> The seeder's placeholder intent is real, but **it was never replaced and the
> product ships on it.**

## The evidence chain, one link per step

| Step | Artefact |
|---|---|
| Next.js calls | `/user/ajax_sidebar_menu_g2g` — `services/navigation/sidebar.ts:41` |
| Route | `routes/user.php:44` |
| Controller | `tblmenumasterG2gController::displaySidebarMenu` |
| Menus from | `tblmenumaster_g2gModel` |
| **Rights from** | **`tblgroupwise_rights_g2gModel`, `where('profile_id', …)`** |
| Applied as | `if (! $this->canView(...)) { continue; }` — **a hard filter, not decoration** |
| Absence means | `return ($rights->can_view ?? 0) == 1;` — **deny** |

## Version history — both prior states, struck through

| Date | State | Why it was wrong |
|---|---|---|
| ~~2026-08-05~~ | ~~**S1** — "the rights matrix carries no information; enforcing it would grant everyone everything"~~ | **Right conclusion, incomplete evidence.** It never established which interface read the table |
| ~~2026-08-07 (morning)~~ | ~~**S3** — "the live table is differentiated; the uniform `can_view=1` is a deliberate placeholder; 4b is invisible to users"~~ | **WRONG.** It found differentiated data in `tblgroupwise_rights` and assumed that was the live table. It is the **Blade** product's. And it let the seeder's stated *intent* stand for the product's *behaviour* (**R10c**) |
| **2026-08-07 (final)** | **S1 — as stated above** | Traced from the frontend call to the controller query. One endpoint, one controller, one table |

**Three states of one finding is untidy. Hiding two of them would be worse.**

## What follows

**4b is not curation. It is the fix**, and it is **the most visible change in the
plan** — the first thing in this phase a person will see.

### Two asymmetries the diff must separate

| Direction | Risk |
|---|---|
| **Action rights** (`add`/`edit`/`delete`) | **PURELY ADDITIVE.** Nothing holds them today, so populating can only **grant**. **Low risk** |
| **`can_view`** | **SUBTRACTIVE.** Every profile currently sees all 157 menus, so real rights **REMOVE** menus from every profile **including Administrator**. **This is where the risk sits, and what the review gate is for** |

---

"""
t = t[:start] + FINAL + t[end:]

# ---- G-SCOPE-01 closed ----
t = t.replace("# G-SCOPE-01 — THE BLADE UI HAS NEVER BEEN AUDITED · **S2** · question for Triz",
              "# G-SCOPE-01 — THE BLADE UI IS OUT OF SCOPE · ✅ **CLOSED 2026-08-07 by decision**")
t = t.replace("""**Question, and it decides X-01c's shape: is the Blade UI in Phase 3, deferred, or
being retired?**""",
"""## ✅ DECIDED 2026-08-07 — OUT OF SCOPE

> **Phase 3 continues with the NEW G2G INTERFACE (Next.js) only. The Blade UI is
> NOT the product being built.**

**Not "deferred with intent to retire" — simply not the product.** If it later
needs work, that is its own phase.

| | |
|---|---|
| The 30 legacy-only menus and `tblgroupwise_rights` (1,254 rows) | **belong to it** |
| Audit it? | **No** |
| Curate its rights? | **No** |
| Retire or modify its tables? | **No. Leave it entirely alone** |
| The scope qualifier on every nav/menu/triage/route-to-menu figure | **now correct BY DEFINITION rather than by accident** |

**⚠️ ONE EXCEPTION STANDS: Blade routes remain inside the C23 tenant-isolation
scope**, because `authMiddleware` accepts a token and they are reachable.
**Security coverage does not narrow with product scope.**""")

# ---- X-01c cancelled ----
t = t.replace("**Tracked as plan item X-01c.**",
"""**❌ X-01c IS CANCELLED.** *(2026-08-07)* **There is nothing to consolidate: two
products, two tables, each keeps its own.** The Blade UI is out of scope
(G-SCOPE-01), so its rights table is not ours to reconcile, migrate or retire.""")
t = re.sub(r"> ## ⛔ X-01c IS A DECISION LIST, NOT A SWITCHOVER.*?R9 applies at that point and not before\.\*\*", "", t, flags=re.S)

io.open(p, "w", encoding="utf-8").write(t)
print("R10c, G-SEC-07 final state, G-SCOPE-01 closed, X-01c cancelled")
