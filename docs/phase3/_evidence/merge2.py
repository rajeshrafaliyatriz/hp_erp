"""Second merge: fold the three sections recovered from the Recycle Bin snapshot
(18:23:51, the last copy before the truncation) into the current 00-progress.md.

Recovered: Status legend, Gate checklist, Module checklist. Those existed only in
this file and were in neither the user's snapshot nor my reconstruction.
"""
import io, os, re

D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"
orig = io.open(os.path.join(D, "00-progress-ORIGINAL-1823.md"), encoding="utf-8").read()
cur = io.open(os.path.join(D, "00-progress.md"), encoding="utf-8").read()

def section(text, title):
    """Everything from '## title' up to the next '## ' at line start."""
    i = text.index("## " + title)
    m = re.search(r"\n## ", text[i + 3:])
    return text[i:i + 3 + m.start()].rstrip() if m else text[i:].rstrip()

wanted = ["Status legend", "Gate checklist", "Module checklist"]
blocks = [section(orig, w) for w in wanted]

marker = "## Standing rules"
add = "\n\n---\n\n".join(blocks)
cur = cur.replace(marker, add + "\n\n---\n\n" + marker, 1)

# provenance row
cur = cur.replace(
    "> | Decisions after 2026-08-05 | **REBUILT** from `10-open-questions.md`, `07-gap-register.md`, `09-implementation-log.md` |",
    "> | Decisions after 2026-08-05 | **REBUILT** from `10-open-questions.md`, `07-gap-register.md`, `09-implementation-log.md` |\n"
    "> | **Status legend · Gate checklist · Module checklist** | **RECOVERED 2026-08-06 from a Recycle Bin snapshot** taken 18:23:51, the last copy before the truncation. These three existed **only** in this file - they were in neither the user's snapshot nor my reconstruction |")

io.open(os.path.join(D, "00-progress.md"), "w", encoding="utf-8").write(cur)
print("merged. bytes now:", len(cur.encode("utf-8")))
for w in wanted:
    print("  restored section:", w)
