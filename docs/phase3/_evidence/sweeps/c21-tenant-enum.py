"""C21 - DEFINITIVE enumeration: every route-reachable controller that resolves
tenant or acting user from the REQUEST BODY rather than from the token.

No sampling. All seven route files. Both directions (trait / no trait).
Output is CANDIDATES (R6) - hand-verified before anything is called a finding.

Deliberately parses BOTH controller reference forms, because the Phase 3 route
audit already lost 52 routes to a regex that only matched the short-alias form
(G-QUAL-02):
    [SomeController::class, 'method']
    [\\App\\Http\\Controllers\\Foo\\SomeController::class, 'method']
"""
import io, os, re, collections, json

BASE = r"C:\Users\MILAN\Downloads\hp_erp"
ROUTES = os.path.join(BASE, "routes")
APP = os.path.join(BASE, "app")
NS = r"[A-Za-z0-9_\\]+"

# ---------- 1. every route, every file ----------
routes = []
route_files = [f for f in sorted(os.listdir(ROUTES)) if f.endswith(".php")]
for rf in route_files:
    txt = io.open(os.path.join(ROUTES, rf), encoding="utf-8", errors="replace").read()
    txt = "\n".join("" if l.strip().startswith("//") else l for l in txt.split("\n"))

    alias = {}
    for m in re.finditer(r"^\s*use\s+(" + NS + r")(?:\s+as\s+(\w+))?\s*;", txt, re.M):
        fq = m.group(1)
        short = m.group(2) or fq.split("\\")[-1]
        alias[short] = fq

    for m in re.finditer(r"Route::(get|post|put|patch|delete|any|match)\s*\(\s*"
                         r"(?:\[[^\]]*\]\s*,\s*)?'([^']+)'\s*,\s*\[\s*\\?(" + NS + r")::class\s*,\s*'(\w+)'", txt):
        verb, uri, cls, meth = m.group(1).upper(), m.group(2), m.group(3), m.group(4)
        short = cls.split("\\")[-1]
        routes.append((rf, verb, uri, short, meth))

    for m in re.finditer(r"Route::(?:api)?[Rr]esource\s*\(\s*'([^']+)'\s*,\s*\\?(" + NS + r")::class", txt):
        uri, cls = m.group(1), m.group(2)
        short = cls.split("\\")[-1]
        for v in ("GET", "POST", "PUT", "PATCH", "DELETE"):
            routes.append((rf, v, uri, short, "[resource]"))

by_ctrl = collections.defaultdict(list)
for r in routes:
    by_ctrl[r[3]].append(r)

print("route files parsed : %d  (%s)" % (len(route_files), ", ".join(route_files)))
print("routes found       : %d   controllers referenced: %d" % (len(routes), len(by_ctrl)))

# ---------- 2. which of those trust the request for tenant / acting user ----------
# Only the RESOLUTION of identity matters. `user_id_target` is a legitimate
# subject parameter (act ON someone), not a claim about who the CALLER is.
TRUST = re.compile(r"\$request->(?:input\(|get\(|header\(|)['\"]?(sub_institute_id|user_id)['\"]?\s*[\),;]")
TRAIT = re.compile(r"use\s+(ResolvesApiIdentity|ResolvesLmsIdentity)\b")
TOKENABLE = re.compile(r"->tokenable\b")

files = {}
for root, _, names in os.walk(APP):
    for n in names:
        if n.endswith(".php"):
            files.setdefault(n[:-4], []).append(os.path.join(root, n))

WRITE = {"POST", "PUT", "PATCH", "DELETE"}
out = []
unresolved = []
for short, rs in sorted(by_ctrl.items()):
    paths = files.get(short, [])
    if not paths:
        unresolved.append(short)
        continue
    for p in paths:
        txt = io.open(p, encoding="utf-8", errors="replace").read()
        hits = [(i, l.strip()[:95]) for i, l in enumerate(txt.split("\n"), 1) if TRUST.search(l)]
        if not hits:
            continue
        verbs = sorted({r[1] for r in rs})
        out.append({
            "controller": short,
            "path": os.path.relpath(p, BASE),
            "trait": bool(TRAIT.search(txt)),
            "reads_tokenable": bool(TOKENABLE.search(txt)),
            "routes": len(rs),
            "verbs": verbs,
            "writes_reachable": bool(WRITE & set(verbs)),
            "route_files": sorted({r[0] for r in rs}),
            "hits": len(hits),
            "samples": hits[:3],
        })

out.sort(key=lambda d: (not d["writes_reachable"], -d["hits"], -d["routes"]))
io.open(os.path.join(BASE, r"docs\phase3\_evidence\sweeps\c21-result.json"), "w",
        encoding="utf-8").write(json.dumps(out, indent=1))

print("\ncontrollers reachable by route AND reading tenant/user from request: %d" % len(out))
print("%-44s %-6s %-9s %-6s %-6s %s" % ("controller", "trait", "tokenable", "routes", "write", "hits"))
for d in out:
    print("%-44s %-6s %-9s %-6d %-6s %-4d %s" % (
        d["controller"], d["trait"], d["reads_tokenable"], d["routes"],
        d["writes_reachable"], d["hits"], ",".join(d["route_files"])))
if unresolved:
    print("\nrouted but no matching file found (investigate, do not assume safe): %s"
          % ", ".join(sorted(set(unresolved))))
