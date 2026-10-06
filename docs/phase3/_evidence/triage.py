"""Group every non-live nav row by top-level module, for the Q-A3 triage pass."""
import io, os, re

SP = os.path.dirname(os.path.abspath(__file__))

menu = []
for line in io.open(os.path.join(SP, "menu-tree.txt"), encoding="utf-8", errors="replace"):
    m = re.search(r"^(\s*)(.+?)\s+id=(\d+)\s+lvl=(\d+)\s+status=(\d+)\s+tenant=\S+\s+(.*)$", line.rstrip())
    if not m:
        continue
    menu.append({
        "depth": len(m.group(1)) // 4, "name": m.group(2).strip(), "id": m.group(3),
        "level": m.group(4), "status": m.group(5), "link": m.group(6).strip(),
    })

stack = {}
for r in menu:
    stack[r["depth"]] = r
    r["parent"] = stack.get(r["depth"] - 1) if r["depth"] > 0 else None

for i, r in enumerate(menu):
    r["isContainer"] = (i + 1 < len(menu) and menu[i + 1]["depth"] > r["depth"])
    vis, p = r["status"] == "1", r["parent"]
    top = r
    while p is not None:
        vis = vis and p["status"] == "1"
        top = p
        p = p["parent"]
    r["effVisible"] = vis
    r["module"] = top["name"]
    # why is it not live?
    if r["status"] == "0":
        r["reason"] = "disabled"
    elif not vis:
        anc, blocker = r["parent"], "?"
        while anc is not None:
            if anc["status"] == "0":
                blocker = anc["name"]
            anc = anc["parent"]
        r["reason"] = "hidden (ancestor '%s' disabled)" % blocker
    else:
        r["reason"] = None

nonlive = [r for r in menu if r["reason"]]
bymod = {}
for r in nonlive:
    bymod.setdefault(r["module"], []).append(r)

print("NON-LIVE NAV ROWS: %d across %d modules\n" % (len(nonlive), len(bymod)))
for mod in sorted(bymod, key=lambda m: -len(bymod[m])):
    rows = bymod[mod]
    print("=" * 100)
    print("%s  (%d rows)" % (mod, len(rows)))
    print("=" * 100)
    for r in sorted(rows, key=lambda r: (r["level"], r["name"])):
        kind = "container" if r["isContainer"] else "leaf"
        print("  id=%-5s lvl=%-2s %-8s %-46s %s" % (r["id"], r["level"], kind, r["name"][:46], r["reason"]))
    print()
