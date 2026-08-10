import io, os, re
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

p = os.path.join(D, "07-gap-register.md")
t = io.open(p, encoding="utf-8").read()

# ---------- ACTION 1: rewrite G-SEC-07 in place, same prominence ----------
m = re.search(r"\| \*\*G-SEC-07\*\* \| \*\*S1\*\* \|.*?\n", t)
if m:
    t = t[:m.start()] + "| **G-SEC-07** | ~~S1~~ → **S3, SUBSTANTIALLY CORRECTED 2026-08-07** | **See the full correction above the provenance section. The original claim — that user roles carry no meaning — was measured on the wrong table and is WITHDRAWN.** |\n" + t[m.end():]

CORRECTION = """---

# ⭐ G-SEC-07 — **SUBSTANTIALLY CORRECTED 2026-08-07** · ~~S1~~ → **S3**

> ## ⛔ THE ORIGINAL CLAIM IS WITHDRAWN
>
> ~~"The rights matrix carries no information. `can_view`=1 on all 4,879 rows,
> every other action 0, and Employee sees more menus than Admin. Enforcing it as
> it stands would grant everyone everything."~~
>
> **That was measured on `tblgroupwise_rights_g2g` — a table the live sidebar does
> not read, holding a DELIBERATE PLACEHOLDER.** It was quoted as *"our user roles
> do not actually mean anything."* **That is not true. Do not repeat it.**

## What is actually the case

**There are two rights systems, one per front-end.**

| | `tblgroupwise_rights` | `tblgroupwise_rights_g2g` |
|---|---|---|
| Serves | **Blade UI**, via `MenuMiddleware` | **Next.js sidebar**, via `displaySidebarMenu` |
| Rows | **1,254** | 4,879 |
| `can_view=1` | 1,253 | all 4,879 |
| `can_add/edit/delete=1` | **784 rows** | **0** |
| Menu tree | `tblmenumaster` (200) | `tblmenumaster_g2g` (188) |

### 1. The live table HAS differentiated rights

| Role | `can_view` per profile | `can_edit` per profile |
|---|---:|---:|
| **Admin** | **95** | 63 |
| HR | 71 | 0 |
| **Employee** | **58** | 38 |

**Admin correctly sees more than Employee.** Roles mean something today.

### 2. The uniform `can_view=1` is DELIBERATE, and says so

`app/Console/Commands/SeedG2gDefaultViewRights.php`, its own `$description`:

> *"Grants `can_view=1` in `tblgroupwise_rights_g2g` for every active profile ×
> menu row that has no rights row yet, **so the new sidebar is not empty until an
> admin curates real rights**."*

**A placeholder awaiting exactly the curation item 4b performs** — not a defect.

### 3. The inversion exists only in the placeholder

**151 / 150 / 136** (Employee / HR / Admin) is the **_g2g** table. In the live
table the ordering is correct. The inversion was an artefact of the placeholder
seeding every profile × menu row uniformly.

### 4. My R9 claim was wrong

I said populating would be visible on the next page load. **`MenuMiddleware` does
not read `_g2g`, so populating it changes nothing on screen.** 4b is **invisible**
to current users.

## What survives

**The action flags are genuinely empty in the new table** — `can_add`, `can_edit`,
`can_delete` are 0 on all 4,879 rows. The new sidebar has **view-only placeholder
rights and no action rights at all.** That is real, and it is what 4b fixes.
**Severity S3**: it blocks curation of the new UI, not the running product.

## How this happened, and the lesson

The measurement was correct; **the table was wrong**. Nothing in the number
revealed that — 4,879 uniform rows look exactly like a broken matrix and exactly
like a fresh placeholder.

> **R17 applied twice this turn, and both times the answer was already written
> down** — Recruiter's permissions in Q-D1, and the placeholder's purpose in the
> seeder's own description. **Check what is already written before concluding a
> defect.**

---

# G-DUP-01 — TWO PARALLEL RIGHTS SYSTEMS FOR ONE CONCEPT · **S2**

**One live with real data, one placeholder for a newer sidebar.** Same duplication
pattern this phase exists to remove, and **the plan must decide it explicitly
rather than inherit it.**

| | Blade | Next.js |
|---|---|---|
| Menu tree | `tblmenumaster` (200) | `tblmenumaster_g2g` (188) |
| Rights | `tblgroupwise_rights` (1,254) | `tblgroupwise_rights_g2g` (4,879) |
| Reader | `MenuMiddleware` | `tblmenumasterG2gController::displaySidebarMenu` |
| Individual rights | `tblindividual_rights` (0 rows) | *(shared)* |

**Menu trees overlap but do not match: 170 ids in both · 30 legacy-only · 18
g2g-only.**

Reconciliation by profile — distinct menus with `can_view=1`:

| Role | Legacy | _g2g |
|---|---:|---:|
| Admin | **200** | 157 |
| HR | **71** | 150 |
| Employee | **169** | 157 |

**Admin loses 43 menus and HR gains 79 if the _g2g seed were taken as-is.** That
diff is the deliverable for the consolidation item, not a side effect of 4b.

**Tracked as plan item X-01c — rights-table consolidation**, its own reviewed
change: reconcile, decide which survives, migrate `MenuMiddleware`, retire the
loser under R8 **with a backup of BOTH tables**. **That is where R9 actually
applies.**

---

# ⚠️ WHAT ELSE WAS MEASURED ON THE _g2g TABLES — inheritance check

| Artefact | Table used | Inherits the error? |
|---|---|---|
| `audit-authorization.py` → **G-SEC-04** | `tblgroupwise_rights_g2g` | ⚠️ **Partly.** Its route-to-menu map is against the **Next.js** tree. Correct **for the Phase 3 product**, but it does **not** describe the Blade UI |
| `dump-menu.php`, `nav-crossref.py` → **Gate A inventory**, `01-inventory.md`, `01b-scope-triage.md` | `tblmenumaster_g2g` | ⚠️ **Same.** The 104-row triage and the whole nav inventory describe the **Next.js** sidebar |
| `audit-auth-sweep.py`, `audit-route-controllers.py` | no rights/menu table | ✅ unaffected |
| **G-NAV-01** (menu row 219 fix) | `tblmenumaster_g2g` | ⚠️ fixed the **Next.js** tree only |

**The honest reading: this is not an error, it is a SCOPE that was never stated.**
Phase 3's product is the **Next.js** front-end, so measuring its tree was right.
**But every nav and menu figure in this phase describes the Next.js sidebar and
says nothing about the Blade UI**, and no document said so until now.

**No number needs re-deriving.** They need the qualifier attached.

---

"""
anchor = "## Data provenance — read before any row-count conclusion"
t = t.replace(anchor, CORRECTION + anchor, 1)
io.open(p, "w", encoding="utf-8").write(t)
print("G-SEC-07 corrected; G-DUP-01 raised; inheritance check recorded")
