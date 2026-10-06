"""S-5: two screens writing one table with DIVERGENT VOCABULARIES.

Generalises the Command Center / Library defect (competency-library.md 2.2-2.3):
one writer offers Published/Draft/Archived, the other Approved/Pending/Cancelled,
and the same column ends up holding both vocabularies.

R10 - what this measures:
  property : "every writer of a column agrees what its values mean"
  proxy    : "two controllers assign different string literals to the same column"
  gap      : passes proxy but fails property - two writers using the SAME literals
             for different meanings is invisible here.
             fails proxy but passes property - one writer handles a legacy value
             the other never emits, which is deliberate.

CANDIDATES (R6). Confirmed against the live column values, because a vocabulary
that no row actually holds is a code smell, not a data problem (R3).
"""
import io, os, re, collections, json

APP = r"C:\Users\MILAN\Downloads\hp_erp\app"

# column => {file => {literals}}
writes = collections.defaultdict(lambda: collections.defaultdict(set))

for root, _, names in os.walk(APP):
    for n in names:
        if not n.endswith(".php"):
            continue
        txt = io.open(os.path.join(root, n), encoding="utf-8", errors="replace").read()
        # 'some_status' => 'Literal'   inside an insert/update array
        for m in re.finditer(r"'([a-z_]*(?:status|state|type|stage|priority)[a-z_]*)'\s*=>\s*'([A-Za-z][\w \-/]{1,30})'", txt):
            col, val = m.group(1), m.group(2)
            writes[col][n].add(val)
        # ternary form:  'status' => $x === 'y' ? 'A' : 'B'
        for m in re.finditer(r"'([a-z_]*(?:status|state|type|stage)[a-z_]*)'\s*=>[^,\n]*\?\s*'([\w ]+)'\s*:\s*'([\w ]+)'", txt):
            writes[m.group(1)][n].update([m.group(2), m.group(3)])

rows = []
for col, byfile in writes.items():
    if len(byfile) < 2:
        continue
    vocabs = {f: v for f, v in byfile.items() if v}
    allv = set().union(*vocabs.values())
    # divergence = at least one writer emits a value another never emits
    disagreeing = {f: sorted(v) for f, v in vocabs.items() if v != allv}
    if len(vocabs) >= 2 and disagreeing:
        rows.append({"column": col, "writers": len(vocabs),
                     "union": sorted(allv),
                     "per_writer": {f: sorted(v) for f, v in vocabs.items()}})

rows.sort(key=lambda r: (-r["writers"], -len(r["union"])))
io.open(r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3\_evidence\sweeps\s5-result.json",
        "w", encoding="utf-8").write(json.dumps(rows, indent=1))

print("status-like columns with >=2 writers AND divergent vocabularies: %d\n" % len(rows))
for r in rows[:12]:
    print("%-26s %d writers   union: %s" % (r["column"], r["writers"], ", ".join(r["union"])[:88]))
    for f, v in list(r["per_writer"].items())[:4]:
        print("      %-46s %s" % (f, ", ".join(v)[:60]))
    print()
