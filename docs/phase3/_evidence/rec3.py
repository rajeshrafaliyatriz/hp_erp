import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"

p = os.path.join(D, "00-progress.md")
t = io.open(p, encoding="utf-8").read()

# provenance pointer to the primary evidence
t = t.replace(
    "> | **Status legend \u00b7 Gate checklist \u00b7 Module checklist** | **RECOVERED 2026-08-06 from a Recycle Bin snapshot**",
    "> | **`00-progress-ORIGINAL-1823.md`** | **PRIMARY EVIDENCE - the recovered 37,983-byte original. KEPT, not deleted (R8).** Every claim about what was lost is checkable against it |\n"
    "> | **Status legend \u00b7 Gate checklist \u00b7 Module checklist** | **RECOVERED 2026-08-06 from a Recycle Bin snapshot**")

# corrected incident timeline
t = t.replace(
    "> A backtick inside a `python -c \"...\"` shell string opened a subshell, the command\n"
    "> mangled, and the write **truncated this file to zero bytes**. It was **untracked\n"
    "> in git**, so no copy existed.",
    "> A backtick inside a `python -c \"...\"` shell string opened a subshell, the command\n"
    "> mangled, and the write **truncated this file to zero bytes**. It was **untracked\n"
    "> in git**, so no copy existed.\n"
    ">\n"
    "> **CORRECTED TIMELINE.** The truncation happened between **18:23 and 18:34**, not\n"
    "> at 19:33 as first reported. **The file was dead for roughly an hour**, and several\n"
    "> subsequent \"updates\" wrote into an empty file **and reported success**.\n"
    ">\n"
    "> **So the loss was one bad command PLUS an hour of unnoticed silent failure** - and\n"
    "> the second half is the larger lesson. A write that silently succeeds into nothing\n"
    "> is the same failure class as a checker that reports a confident wrong number:\n"
    "> **it looks like it worked.**")

# R18 amendment
t = t.replace(
    "**R18** commit\n`docs/phase3/` after every write to this file.",
    "**R18** commit\n`docs/phase3/` after every write to this file, **and assert after every write that\nthe file is non-empty and still contains an expected marker line**.")

io.open(p, "w", encoding="utf-8").write(t)

# G-FLOW-24 prominence in the register
p = os.path.join(D, "07-gap-register.md")
t = io.open(p, encoding="utf-8").read()
t = t.replace(
    "### The direction, now beyond doubt",
    "### \u2b50 The first correction to move in the REASSURING direction\n\n"
    "**`G-FLOW-24`, 2026-08-06.** Every prior correction in this phase made a finding\n"
    "worse. This one made one **better**: `delay_category` is empty because **exactly\n"
    "one task of 2,271 has ever reached `ON HOLD`** - not because nothing writes it.\n"
    "`MyTasksController.php:164` validates a closed enum and `:205` writes it.\n\n"
    "**It is provenance, not a structural defect.**\n\n"
    "> **A register that only ever escalates is being fed, not checked.** Recording the\n"
    "> one item that got better is how you tell the difference.\n\n"
    "**Carried into golden thread 2:** the overdue/stall rule **cannot rely on\n"
    "`delay_category` being populated**, because the state that fills it has been\n"
    "reached once. In production it would populate; on this data it proves nothing.\n\n"
    "---\n\n"
    "### The direction, now beyond doubt")
io.open(p, "w", encoding="utf-8").write(t)
print("records applied")
