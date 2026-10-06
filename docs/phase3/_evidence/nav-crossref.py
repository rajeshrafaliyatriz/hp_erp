"""
Cross-reference the backend navigation tree (tblmenumaster_g2g, dumped to
menu-tree.txt) against the frontend content maps, to find:
  - menu entries a user can click that render nothing  (dead navigation)
  - built screens no menu points at                    (unreachable screens)
"""
import io, os, re, json, glob

SP = os.path.dirname(os.path.abspath(__file__))
FE = r"C:\Users\MILAN\Downloads\g2gv0"

# ---- 1. menu rows from the dump
menu = []
for line in io.open(os.path.join(SP, "menu-tree.txt"), encoding="utf-8", errors="replace"):
    m = re.search(r"^(\s*)(.+?)\s+id=(\d+)\s+lvl=(\d+)\s+status=(\d+)\s+tenant=\S+\s+(.*)$", line.rstrip())
    if not m:
        continue
    menu.append({
        "depth": len(m.group(1)) // 4,
        "name": m.group(2).strip(),
        "id": m.group(3),
        "level": m.group(4),
        "status": m.group(5),
        "link": m.group(6).strip(),
    })

# ---- 2. content-map registrations
# accessLink is sometimes a string literal and sometimes an imported constant
# (M1 uses ORG_PROFILE_ACCESS_LINK etc). Resolving the constants matters: without
# it all six Organizational Management screens look like broken navigation when
# they are correctly wired.
CONSTS = {}
navsrc = io.open(os.path.join(FE, "lib", "gtg-navigation.ts"), encoding="utf-8").read()
for m in re.finditer(r"export const (\w+)\s*=\s*'([^']+)'", navsrc):
    CONSTS[m.group(1)] = m.group(2)

maps = {}
loader_keys = set()
umap = io.open(os.path.join(FE, "hooks", "use-content-map.ts"), encoding="utf-8").read()
for m in re.finditer(r"^\s*'?([\w-]+)'?:\s*\(\)\s*=>\s*import\('\./content-map-(m\d+)'\)", umap, re.M):
    loader_keys.add((m.group(1), m.group(2)))

for path in sorted(glob.glob(os.path.join(FE, "hooks", "content-map-m*.ts"))):
    key = os.path.basename(path).replace("content-map-", "").replace(".ts", "")
    src = io.open(path, encoding="utf-8").read()
    entries = []
    for m in re.finditer(r"\{([^{}]*component:\s*(\w+)[^{}]*)\}", src):
        body, comp = m.group(1), m.group(2)
        sub = re.search(r"submenuId:\s*'([^']+)'", body)
        mid = re.search(r"menuId:\s*'([^']+)'", body)
        link = re.search(r"accessLink:\s*'([^']+)'", body)
        if link:
            resolved = link.group(1)
        else:
            ref = re.search(r"accessLink:\s*([A-Z_][A-Z0-9_]*)", body)
            resolved = CONSTS.get(ref.group(1)) if ref else None
        entries.append({
            "component": comp,
            "submenuId": sub.group(1) if sub else None,
            "menuId": mid.group(1) if mid else None,
            "accessLink": resolved,
        })
    maps[key] = entries

registered_ids, registered_links = set(), set()
for k, entries in maps.items():
    for e in entries:
        for v in (e["submenuId"], e["menuId"]):
            if v:
                registered_ids.add(v)
        if e["accessLink"]:
            registered_links.add(e["accessLink"].rstrip("/"))

# ---- 2b. derive container vs leaf, and EFFECTIVE visibility
# A node with status=1 whose parent is status=0 is still invisible to the user,
# so raw status alone overstates what is reachable. Depth ordering lets the
# ancestor chain be rebuilt from the indented dump.
stack = {}
for r in menu:
    stack[r["depth"]] = r
    r["parent"] = stack.get(r["depth"] - 1) if r["depth"] > 0 else None

for i, r in enumerate(menu):
    r["isContainer"] = (i + 1 < len(menu) and menu[i + 1]["depth"] > r["depth"])
    vis, p = r["status"] == "1", r["parent"]
    while p is not None:
        vis = vis and p["status"] == "1"
        p = p["parent"]
    r["effVisible"] = vis

# ---- 3. compare
leaf = [r for r in menu if r["link"] and not r["link"].startswith("(no")]
dead, ok, external = [], [], []
for r in leaf:
    if r["link"].startswith("http"):
        external.append(r)
        continue
    hit = r["id"] in registered_ids or r["link"].rstrip("/") in registered_links
    (ok if hit else dead).append(r)

lines = []
A = lines.append
A("MENU ROWS PARSED: %d   |   registered ids: %d   registered accessLinks: %d"
  % (len(menu), len(registered_ids), len(registered_links)))
A("")
A("CONTENT MAP FILES AND LOADER REGISTRATION")
A("-" * 100)
for k in sorted(maps):
    reg = [lk for lk, mk in loader_keys if mk == k]
    A("  %-4s entries=%-4d loader keys: %s" % (k, len(maps[k]), ", ".join(reg) if reg else "*** NOT REGISTERED - module unreachable ***"))
A("")
A("BROKEN NAVIGATION - user can SEE and CLICK it, and nothing renders")
A("(effectively visible = itself and every ancestor status=1; leaf = has no children)")
A("-" * 100)
d1 = [r for r in dead if r["effVisible"] and not r["isContainer"]]
for r in sorted(d1, key=lambda r: r["link"]):
    A("  id=%-5s %-46s %s" % (r["id"], r["name"][:46], r["link"][:70]))
A("  TOTAL: %d" % len(d1))
A("")
A("CONTAINER NODES WITH NO SCREEN (expected - they only expand a submenu)")
A("-" * 100)
dc = [r for r in dead if r["effVisible"] and r["isContainer"]]
for r in sorted(dc, key=lambda r: r["link"]):
    A("  id=%-5s %-46s %s" % (r["id"], r["name"][:46], r["link"][:70]))
A("  TOTAL: %d" % len(dc))
A("")
A("HIDDEN BY AN ANCESTOR - status=1 itself but an ancestor is status=0")
A("-" * 100)
hid = [r for r in menu if r["status"] == "1" and not r["effVisible"]]
for r in sorted(hid, key=lambda r: r["name"]):
    par = r["parent"]["name"] if r["parent"] else "?"
    A("  id=%-5s %-42s (blocked by: %s)" % (r["id"], r["name"][:42], par[:34]))
A("  TOTAL: %d" % len(hid))
A("")
A("DISABLED MENU ENTRIES (status=0 - built or planned, hidden from users)")
A("-" * 100)
d0 = [r for r in menu if r["status"] == "0"]
for r in sorted(d0, key=lambda r: r["name"]):
    A("  id=%-5s lvl=%-2s %-46s %s" % (r["id"], r["level"], r["name"][:46], (r["link"] or "")[:60]))
A("  TOTAL: %d" % len(d0))
A("")
A("EXTERNAL LINKS IN NAVIGATION (leaves the product)")
A("-" * 100)
for r in external:
    A("  id=%-5s status=%s %-30s %s" % (r["id"], r["status"], r["name"][:30], r["link"][:80]))
A("")
A("DUPLICATE access_link VALUES (two menu items pointing at the same screen)")
A("-" * 100)
seen = {}
for r in leaf:
    seen.setdefault(r["link"], []).append(r)
for link, rows in sorted(seen.items()):
    if len(rows) > 1:
        A("  %s" % link[:80])
        for r in rows:
            A("      id=%-5s status=%s %s" % (r["id"], r["status"], r["name"]))
A("")
A("SUMMARY: wired=%d  broken-nav=%d  containers=%d  hidden-by-ancestor=%d  disabled=%d  external=%d"
  % (len(ok), len(d1), len(dc), len(hid), len(d0), len(external)))

out = "\n".join(lines)
io.open(os.path.join(SP, "crossref.txt"), "w", encoding="utf-8").write(out)
print(out)
