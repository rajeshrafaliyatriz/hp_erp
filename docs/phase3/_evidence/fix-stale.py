import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"
p = os.path.join(D, "00-progress.md")
t = io.open(p, encoding="utf-8").read()

old = "| C | `06-feature-audit/*` write-ups | **In Progress** | **2 of 32 delivered.** C2 order: Competency \u2192 Organization \u2192 LMS \u2192 Task \u2192 Talent \u2192 rest |"
new = ("| C | `06-feature-audit/*` write-ups | \u2705 **CLOSED** | **All six module write-ups delivered.** 30 audited \u00b7 1 duplicate \u00b7 1 out of scope = 32 |\n"
       "| C | `12-gate-c-verification.md` | \u2705 **VERIFIED** | V4 sample error rate **3.3%** (1 of 30); V7 reconciliation **clean**. **Gate C stands** |\n"
       "| D | `08-connection-plan.md` | **In Progress** | \u00a71\u2013\u00a73 written (diagnosis, 9 threads traced, dependency tiers). \u00a74\u2013\u00a711 next |")

assert old in t, "stale row not found"
t = t.replace(old, new)

# the stale row came in verbatim with the recovered Gate checklist; note why
t = t.replace("> | **Status legend \u00b7 Gate checklist \u00b7 Module checklist** | **RECOVERED 2026-08-06 from a Recycle Bin snapshot**",
              "> | **Status legend \u00b7 Gate checklist \u00b7 Module checklist** | **RECOVERED 2026-08-06 from a Recycle Bin snapshot** \u2014 \u26a0\ufe0f the Gate checklist came back with a **stale row** (*\"2 of 32 delivered\"*) that survived the merge unnoticed until 2026-08-07. **Corrected. A recovered section is a snapshot, not a current statement**")

io.open(p, "w", encoding="utf-8").write(t)
assert "2 of 32 delivered" not in t
print("stale row fixed; contradiction gone")
