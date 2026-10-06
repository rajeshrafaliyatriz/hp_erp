import io, os, re
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

p = os.path.join(D, "07-gap-register.md")
t = io.open(p, encoding="utf-8").read()

BLADE = """---

# G-SCOPE-01 — THE BLADE UI HAS NEVER BEEN AUDITED · **S2** · question for Triz

**Not a documentation gap — an unexamined surface.** Every nav, menu, triage and
route-to-menu figure in this phase describes the **Next.js** sidebar. The Blade UI
has its own menu tree, its own rights table, and **its screens were never in the
audit's scope.**

## The evidence, both directions

| For "live and maintained" | |
|---|---|
| Blade views on disk | **173** |
| Controllers returning `view()` | **21** (46 call sites) |
| **Last change to `resources/views`** | **2026-07-31** |
| Last change to `routes/api.php`, for comparison | 2026-08-03 |
| Its own middleware stack | `MenuMiddleware` + `authMiddleware`, actively used |
| Its rights table | **1,254 rows, genuinely differentiated** — someone configured this |

| For "legacy on the way out" | |
|---|---|
| — | **No evidence found.** No deprecation notice, no redirect-to-Next.js, no removal commits |

**Verdict on the evidence: the Blade UI is LIVE and was touched three days before
the API surface.** Nothing suggests retirement.

## What was and was not covered — the precision matters

| | Covered? |
|---|---|
| **Blade ROUTES, tenant isolation** | ✅ **YES.** The C23 guard ran across **all six route files**, including `web.php`, `hrms.php`, `lms.php`, `settings.php`, `user.php`. Blade's routes are inside the 46 failures and the 66 token-reachable controllers |
| Blade **screens, menus, rights** | ❌ **NO.** Gate C's six write-ups audited the Next.js screens only |
| Blade **menu tree** (`tblmenumaster`, 200 rows, **30 not in `_g2g`**) | ❌ **NO** |
| Blade **rights** (`tblgroupwise_rights`, 1,254 rows) | ❌ **NO** |

**So the security hole is narrower than "unaudited surface" suggests** — tenant
isolation *was* tested there, because `authMiddleware` accepts a token and the
guard walked every route file. **What is unexamined is its features, menus and
rights.**

## Recommendation

**Treat it as LEGACY, DEFERRED — and say so explicitly, rather than leaving it
undeclared.**

Reasoning:

1. **Phase 3's product is the Next.js front-end.** Every golden thread, every module write-up and the whole connection plan target it. Auditing Blade now would restart Gate C for a UI the plan does not build on.
2. **Its routes are already covered for the one risk that matters most** — tenant isolation, via C23.
3. **The 30 legacy-only menus go with it.** They are screens the Next.js product does not have and, on the evidence of the triage, does not intend to.
4. **But it is live**, so "deferred" must mean *declared and dated*, not *forgotten* — with a stated intent to retire it, and X-01c's consolidation as the first step.

⚠️ **If Triz says it is staying**, then it needs its own Gate C pass and its rights
table needs the same curation as `_g2g` — and X-01c cannot retire either table.

**Question, and it decides X-01c's shape: is the Blade UI in Phase 3, deferred, or
being retired?**

---

"""
anchor = "## Data provenance — read before any row-count conclusion"
t = t.replace(anchor, BLADE + anchor, 1)

# ---- the qualifier, attached to every affected figure ----
QUAL = ("\n\n> ⚠️ **SCOPE QUALIFIER (2026-08-07):** this figure describes the **Next.js sidebar**\n"
        "> (`tblmenumaster_g2g`). It says nothing about the Blade UI, which has its own menu\n"
        "> tree of 200 rows — 30 of them not present here. **No number needs re-deriving;\n"
        "> the qualifier travels with it.** See `G-SCOPE-01`.\n")

for fname, needle in [("01-inventory.md", None), ("01b-scope-triage.md", None)]:
    fp = os.path.join(D, fname)
    if os.path.exists(fp):
        ft = io.open(fp, encoding="utf-8").read()
        if "SCOPE QUALIFIER" not in ft:
            lines = ft.split("\n")
            # after the first heading block
            for i, l in enumerate(lines[:12]):
                if l.startswith("#"):
                    continue
            ft = lines[0] + QUAL + "\n" + "\n".join(lines[1:])
            io.open(fp, "w", encoding="utf-8").write(ft)
            print("qualifier attached to", fname)

for marker in ["## G-SEC-04 — The route-to-menu map is not reliable enough to enforce against",
               "## G-NAV-01 —"]:
    if marker in t and "SCOPE QUALIFIER" not in t[t.index(marker):t.index(marker) + 1200]:
        t = t.replace(marker, marker, 1)
        idx = t.index(marker)
        end = t.index("\n\n", idx)
        t = t[:end] + QUAL + t[end:]

# ---- X-01c reframed: a decision list, not a switchover ----
t = t.replace("**Tracked as plan item X-01c — rights-table consolidation**, its own reviewed\nchange: reconcile, decide which survives, migrate `MenuMiddleware`, retire the\nloser under R8 **with a backup of BOTH tables**. **That is where R9 actually\napplies.**",
"""**Tracked as plan item X-01c.**

> ## ⛔ X-01c IS A DECISION LIST, NOT A SWITCHOVER
>
> **Admin −43 menus and HR +79 is a redesign delivered as a data change.**
> **Neither direction may be applied by taking the `_g2g` seed as-is.**
>
> | Direction | Why it gets its own scrutiny |
> |---|---|
> | **LOSSES** (Admin −43) | **a support incident if wrong.** Each must be confirmed as intended |
> | **GAINS** (HR +79) | **a PERMISSION INCREASE nobody approved.** This is the one with security consequences and it gets the closer look |
>
> **The deliverable is the per-role, per-menu list of what changes and in which
> direction.** Triz decides each direction; only then does it apply. **Backups of
> BOTH tables first. R9 applies at that point and not before.**""")

# ---- the surviving G-SEC-07 finding, stated plainly ----
t = t.replace("**Severity S3**: it blocks curation of the new UI, not the running product.",
"""**Severity S3.**

> **THE SURVIVING FINDING, to be stated exactly this way wherever it appears:**
> **the new sidebar has view-only placeholder rights and NO action rights at all.
> That blocks curation of the new UI, not the running product.**""")

io.open(p, "w", encoding="utf-8").write(t)
print("G-SCOPE-01 raised; qualifier attached; X-01c reframed")
