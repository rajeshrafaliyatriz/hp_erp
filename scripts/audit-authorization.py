"""
Authorization coverage audit — distinct from authentication.

`audit-auth-sweep.py` answers "is the caller identified?". This answers the
different and commercially harder question: "is the caller ALLOWED?".

A route can be perfectly authenticated and still let any employee delete another
department's data. Enterprise security reviews test authorization, not
authentication.

Also emits the route -> menu mapping needed before permission middleware keyed on
tblgroupwise_rights_g2g.menu_id can be applied (Gate B amendment A3).

Usage:  python scripts/audit-authorization.py [--csv]
"""
import io, os, re, sys, json, collections

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ROUTES = os.path.join(BASE, "routes", "api.php")
CTRL = os.path.join(BASE, "app", "Http", "Controllers")
MENU_DUMP = os.path.join(BASE, "docs", "phase3", "_evidence", "menu-tree.txt")

src = io.open(ROUTES, encoding="utf-8", errors="replace").read()

# strip commented-out lines, keep line numbers
lines = ["" if l.strip().startswith(("//", "#")) else l for l in src.split("\n")]
src_nc = "\n".join(lines)

aliases = {}
for m in re.finditer(r"^use\s+([\w\\]+)(?:\s+as\s+(\w+))?\s*;", src, re.M):
    aliases[m.group(2) or m.group(1).split("\\")[-1]] = m.group(1)

# The class reference is either a short alias resolved by a `use` statement
# (`Foo::class`) or a fully-qualified inline name (`\App\Http\Controllers\...::class`).
# An earlier version matched only `\w+::class` and therefore silently dropped 52
# routes - including 11 on assignmentController - from the authorization audit.
# Under-counting an authorization audit is worse than not running one.
route_re = re.compile(
    r"Route::(get|post|put|patch|delete|any)\s*\(\s*'([^']+)'\s*,\s*\[\s*\\?([\w\\]+)::class\s*,"
    r"\s*'([^']+)'\s*\]\s*\)((?:->[^;\n]*)*)\s*;"
)

# group blocks that carry middleware
def brace_end(s, i):
    d, j = 0, i
    while j < len(s):
        if s[j] == "{": d += 1
        elif s[j] == "}":
            d -= 1
            if d == 0: return j
        j += 1
    return len(s) - 1

groups = []
for m in re.finditer(r"Route::[^;\n]*?->middleware\(([^)]*)\)[^;\n]*?->group\(function\s*\([^)]*\)\s*\{", src_nc):
    o = src_nc.index("{", m.end() - 1)
    c = brace_end(src_nc, o)
    groups.append((src_nc[:m.start()].count("\n") + 1, src_nc[:c].count("\n") + 1, m.group(1)))
for m in re.finditer(r"Route::group\(\s*\[[^\]]*'middleware'\s*=>\s*([^,\]]+)[^\]]*\]\s*,\s*function\s*\([^)]*\)\s*\{", src_nc):
    o = src_nc.index("{", m.end() - 1)
    c = brace_end(src_nc, o)
    groups.append((src_nc[:m.start()].count("\n") + 1, src_nc[:c].count("\n") + 1, m.group(1)))

# ---- authorization markers inside controller methods
AUTHZ = re.compile(
    r"\$this->(guardAdmin|guardAuthoring|guardLmsProfile|authorize)\s*\(|"
    r"user_profile_id|tbluserprofilemaster|isEmployee"
)
_cache = {}
def method_body(fqcn, method):
    key = (fqcn, method)
    if key in _cache: return _cache[key]
    rel = fqcn.replace("App\\Http\\Controllers\\", "").replace("\\", os.sep) + ".php"
    p = os.path.join(CTRL, rel)
    body = None
    if os.path.exists(p):
        s = io.open(p, encoding="utf-8", errors="replace").read().replace("\r\n", "\n")
        m = re.search(r"function\s+" + re.escape(method) + r"\s*\(", s)
        if m:
            i = s.find("{", m.end())
            if i >= 0:
                body = s[i:brace_end(s, i) + 1]
                # follow one level of self-delegation
                for call in set(re.findall(r"\$this->(\w+)\s*\(", body)):
                    m2 = re.search(r"function\s+" + re.escape(call) + r"\s*\(", s)
                    if m2:
                        i2 = s.find("{", m2.end())
                        if i2 >= 0:
                            body += "\n" + s[i2:brace_end(s, i2) + 1]
    _cache[key] = body
    return body

rows = []
for m in route_re.finditer(src_nc):
    verb, uri, alias, method, chain = m.groups()
    line = src_nc[:m.start()].count("\n") + 1
    # a fully-qualified inline reference is already the FQCN; a bare alias needs
    # the `use` block to resolve it
    fqcn = alias if "\\" in alias else aliases.get(alias, alias)
    # last two namespace segments: `LibraryController` alone is ambiguous across
    # Competency / lms / libraries, and an ambiguous label in a security report
    # sends someone to the wrong file.
    parts = [p for p in fqcn.split("\\") if p not in ("App", "Http", "Controllers")]
    alias = "\\".join(parts[-2:]) if len(parts) > 1 else fqcn.split("\\")[-1]

    gmw = [g[2] for g in groups if g[0] < line < g[1]]
    mw = (chain or "") + " " + " ".join(gmw)

    if "task.permission" in mw:
        kind, how = "ROLE-GATED", "task.permission middleware"
    elif "profile:" in mw:
        kind, how = "ROLE-GATED", "profile middleware"
    else:
        body = method_body(fqcn, method)
        if body and AUTHZ.search(body):
            kind, how = "ROLE-GATED", "in-controller profile check"
        elif "api.token" in mw or "task.sanitize" in mw:
            kind, how = "AUTH-ONLY", "token required, no role check"
        elif body is None:
            kind, how = "UNKNOWN", "method not found"
        else:
            kind, how = "AUTH-ONLY", "no role check found"
    rows.append({"verb": verb.upper(), "uri": uri, "ctrl": alias, "method": method,
                 "line": line, "kind": kind, "how": how,
                 "write": verb.lower() in ("post", "put", "patch", "delete")})

# ---- route -> menu mapping (A3)
menus = []
if os.path.exists(MENU_DUMP):
    for l in io.open(MENU_DUMP, encoding="utf-8", errors="replace"):
        mm = re.search(r"^(\s*)(.+?)\s+id=(\d+)\s+lvl=(\d+)\s+status=(\d+)\s+tenant=\S+\s+(.*)$", l.rstrip())
        if mm and mm.group(6).startswith("/module/"):
            menus.append({"id": mm.group(3), "name": mm.group(2).strip(),
                          "slug": mm.group(6).replace("/module/", "").strip("/")})

def map_menu(uri):
    toks = set(t for t in re.split(r"[/_-]", uri.lower()) if len(t) > 3 and not t.startswith("{"))
    best, score = None, 0
    for mn in menus:
        mt = set(t for t in re.split(r"[/_-]", mn["slug"].lower()) if len(t) > 3)
        s = len(toks & mt)
        if s > score:
            best, score = mn, s
    return (best, score) if score >= 1 else (None, 0)

for r in rows:
    mn, sc = map_menu(r["uri"])
    r["menu_id"] = mn["id"] if mn else None
    r["menu"] = mn["name"] if mn else None
    r["confidence"] = sc

# ---- report
c = collections.Counter(r["kind"] for r in rows)
w = collections.Counter(r["kind"] for r in rows if r["write"])
print("API routes analysed: %d  (writes: %d)\n" % (len(rows), sum(1 for r in rows if r["write"])))
print("  %-12s %5s %8s" % ("", "all", "writes"))
for k in ("ROLE-GATED", "AUTH-ONLY", "UNKNOWN"):
    print("  %-12s %5d %8d" % (k, c.get(k, 0), w.get(k, 0)))

unguarded_writes = [r for r in rows if r["kind"] == "AUTH-ONLY" and r["write"]]
print("\nWRITE routes with NO authorization check: %d" % len(unguarded_writes))
by_ctrl = collections.Counter(r["ctrl"] for r in unguarded_writes)
print("\n  top controllers by unguarded write count:")
for ctrl, n in by_ctrl.most_common(25):
    print("    %-46s %d" % (ctrl, n))

unmapped = [r for r in rows if not r["menu_id"]]
print("\nROUTES THAT MAP TO NO MENU: %d  (A3 - most likely to stay unguarded)" % len(unmapped))
for ctrl, n in collections.Counter(r["ctrl"] for r in unmapped).most_common(20):
    print("    %-46s %d" % (ctrl, n))

out = os.path.join(BASE, "docs", "phase3", "_evidence", "authorization-coverage.json")
io.open(out, "w", encoding="utf-8").write(json.dumps(rows, indent=1))
print("\nfull per-route detail -> %s" % out)

if "--csv" in sys.argv:
    csv = os.path.join(BASE, "docs", "phase3", "_evidence", "route-to-menu-map.csv")
    with io.open(csv, "w", encoding="utf-8") as fh:
        fh.write("verb,uri,controller,method,api_line,authorization,how,menu_id,menu,confidence\n")
        for r in sorted(rows, key=lambda r: (r["kind"] != "AUTH-ONLY", r["ctrl"], r["uri"])):
            fh.write('%s,"%s",%s,%s,%d,%s,"%s",%s,"%s",%d\n' % (
                r["verb"], r["uri"], r["ctrl"], r["method"], r["line"], r["kind"],
                r["how"], r["menu_id"] or "", r["menu"] or "", r["confidence"]))
    print("route -> menu map -> %s" % csv)
