import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

# 1. progress: Task module done
p = os.path.join(D, "00-progress.md")
t = io.open(p, encoding="utf-8").read()
t = t.replace("### Module write-ups: **17 of 32 sub-modules**",
              "### Module write-ups: **19 of 32 sub-modules**")
t = t.replace("| Task | 2 | pending |", "| **Task** | 2 | \u2705 `task.md` |")
t = t.replace("nothing leaves that section", "nothing leaves that section")
# R12 amendment
t = t.replace("**R12** land a queued\nitem every turn",
              "**R12** land a queued\nitem every turn *(a turn spent entirely on an incident counts as landed work,\nprovided the incident is reported and closed)*")
# Still-owed rule
t = t.replace("## Still owed \u2014 carried explicitly so they do not drop again",
              "## Still owed \u2014 carried explicitly so they do not drop again\n\n> **Rule: nothing leaves this section without either a commit reference or an\n> explicit decision not to do it.**")
io.open(p, "w", encoding="utf-8").write(t)

# 2. register: correct G-FLOW-24
p = os.path.join(D, "07-gap-register.md")
t = io.open(p, encoding="utf-8").read()
t = t.replace(
 "| `G-FLOW-24` | `delay_category` 0 rows | **Structural, not provenance** \u2014 no code path writes it, so it would be 0 in production too |",
 "| `G-FLOW-24` | `delay_category` 0 rows | \u26a0\ufe0f **CORRECTED 2026-08-06 \u2014 it IS provenance.** A write path **does** exist: `MyTasksController.php:164` validates a closed enum and `:205` writes it, gated on `status === 'ON HOLD'`. **Exactly 1 task of 2,271 has ever been ON HOLD.** The column is empty because the state that fills it has been reached once, not because nothing writes it. **The first finding in this phase to move in the reassuring direction** \u2014 recorded because a register that only ever escalates is not being checked properly |")
io.open(p, "w", encoding="utf-8").write(t)
print("updated")
