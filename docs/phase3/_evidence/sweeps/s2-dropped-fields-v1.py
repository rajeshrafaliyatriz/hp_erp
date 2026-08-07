"""S-2: the form sends a field the validator or the insert silently drops.

Generalises the Command Center defect (competency-library.md 2.1): the UI sends
competency_type and jobrole, the validator accepts neither, the insert writes
neither, and the user gets a success message.

R10 - what this measures:
  property : "every field the UI sends is either stored or explicitly rejected"
  proxy    : "a key present in a frontend payload literal is absent from the
              controller's validator rules AND from its insert/update arrays"
  gap      : passes proxy but fails property - a field renamed between client and
             server (sent as `name`, stored as `title`) reads as dropped when it
             is not. Every hit is hand-checked before it is called a finding (R6).
             Fails proxy but passes property - a field dropped inside a helper
             this script does not follow.

CANDIDATES, never findings.
"""
import io, os, re, collections, json

FE = r"C:\Users\MILAN\Downloads\g2gv0"
BE = r"C:\Users\MILAN\Downloads\hp_erp\app"

# ---- 1. frontend payload literals: keys sent to an API
sent = collections.defaultdict(set)          # file -> {keys}
for root, _, names in os.walk(FE):
    if "node_modules" in root or ".next" in root:
        continue
    for n in names:
        if not n.endswith((".ts", ".tsx")):
            continue
        p = os.path.join(root, n)
        txt = io.open(p, encoding="utf-8", errors="replace").read()
        # payload object literals passed to a service/post/put call
        for m in re.finditer(r"(?:post|put|patch|create|update|save)\s*\([^)]{0,120}?\{([^{}]{20,600})\}", txt, re.I | re.S):
            for km in re.finditer(r"(?:^|[\s,{])([a-z][a-z0-9_]{2,40})\s*:", m.group(1), re.M):
                sent[n].add(km.group(1))

# ---- 2. backend: validator rule keys and insert/update array keys per file
accepted = collections.defaultdict(set)
for root, _, names in os.walk(BE):
    for n in names:
        if not n.endswith(".php"):
            continue
        txt = io.open(os.path.join(root, n), encoding="utf-8", errors="replace").read()
        for m in re.finditer(r"'([a-z][a-z0-9_]{2,40})'\s*=>\s*'(?:required|nullable|sometimes|integer|string|numeric|boolean|date|array|in:|exists:)", txt):
            accepted[n].add(m.group(1))
        for m in re.finditer(r"'([a-z][a-z0-9_]{2,40})'\s*=>\s*\$request->", txt):
            accepted[n].add(m.group(1))
        for m in re.finditer(r"\$request->(?:input|get)\(\s*'([a-z][a-z0-9_]{2,40})'", txt):
            accepted[n].add(m.group(1))

all_accepted = set()
for s in accepted.values():
    all_accepted |= s

IGNORE = {"headers", "method", "body", "params", "signal", "cache", "credentials",
          "className", "children", "onchange", "onclick", "value", "label"}

rows = []
for f, keys in sorted(sent.items()):
    orphan = sorted(k for k in keys if k not in all_accepted and k not in IGNORE)
    if orphan:
        rows.append({"frontend_file": f, "sent_keys": len(keys),
                     "never_accepted_anywhere": orphan})

rows.sort(key=lambda r: -len(r["never_accepted_anywhere"]))
io.open(r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3\_evidence\sweeps\s2-result.json",
        "w", encoding="utf-8").write(json.dumps(rows, indent=1))

print("frontend files with a payload literal : %d" % len(sent))
print("distinct keys accepted somewhere in the backend : %d" % len(all_accepted))
print("files sending >=1 key no backend file ever reads : %d" % len(rows))
print()
for r in rows[:20]:
    print("  %-46s %2d orphan of %2d : %s" % (
        r["frontend_file"], len(r["never_accepted_anywhere"]), r["sent_keys"],
        ", ".join(r["never_accepted_anywhere"][:6])))
