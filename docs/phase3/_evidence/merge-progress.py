"""One-shot merge of the recovered 00-progress.md sections into the reconstruction.

Written to a FILE rather than passed through a shell string: the loss this repairs
was caused by shell interpretation of a backtick, and every retry through `bash -c`
hit the same class of problem. (R18)
"""
import io, os

D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"
cur = io.open(os.path.join(D, "00-progress.md"), encoding="utf-8").read()
rec = io.open(os.path.join(D, "00-progress-RECOVERED-SECTIONS.md"), encoding="utf-8").read()

sections = rec[rec.index("## Decisions made"):].rstrip()

cur = cur.replace(
    "> **Fixed going forward:** this file is written with the Write tool or a heredoc,\n"
    "> **never** through a shell string containing backticks.",
    "> **Fixed going forward:** this file is written with the Write tool, **never**\n"
    "> through a shell string. **R18: committed to git after every write.**\n"
    ">\n"
    "> ### Recovered vs rebuilt\n"
    ">\n"
    "> | Section | Provenance |\n"
    "> |---|---|\n"
    "> | Gate state, C17/C18, G-DATA-06, rules R1-R18, sweep conclusion, 17-of-32, Gate D thread, instruments | **REBUILT** (newer, and correct) |\n"
    "> | **Decisions made** - 43 dated rows to 2026-08-05 | **RECOVERED** from the snapshot |\n"
    "> | **Deferred scope** - 6 items | **RECOVERED.** It had been silently dropped |\n"
    "> | **Changes executed** - G-NAV-01 | **RECOVERED** |\n"
    "> | Decisions after 2026-08-05 | **REBUILT** from `10-open-questions.md`, `07-gap-register.md`, `09-implementation-log.md` |")

cur = cur.replace(
    "---\n\n## Open questions\n\n`10-open-questions.md` \u2014 **24 questions, all answered.**\n\n---\n\n## Queue",
    "---\n\n## Queue")

head, queue = cur.split("## Queue", 1)
queue = "## Queue" + queue

post = io.open(os.path.join(D, "_evidence", "post-snapshot-decisions.md"), encoding="utf-8").read()

io.open(os.path.join(D, "00-progress.md"), "w", encoding="utf-8").write(
    head + sections + "\n\n---\n\n" + post.rstrip() + "\n\n---\n\n" + queue)

n = len(io.open(os.path.join(D, "00-progress.md"), encoding="utf-8").read().split("\n"))
print("merged OK - 00-progress.md is now %d lines" % n)
