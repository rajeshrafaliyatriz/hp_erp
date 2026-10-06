"""
C1 — calibrate the agent-generated raw inventory.

Checks each catalogued feature on three mechanically verifiable claims:
  A. the cited source file exists
  B. the cited line number is within that file
  C. the cited API endpoint exists in routes/api.php

C is the strongest signal: a hallucinated endpoint is unambiguous.
Rows failing any check are printed for hand-classification.

Usage: python docs/phase3/_evidence/verify-inventory.py <raw-inventory.json> [file-filter]
"""
import io, json, os, re, sys, collections

BASE = r"C:\Users\MILAN\Downloads\hp_erp"
FE = r"C:\Users\MILAN\Downloads\g2gv0"

path = sys.argv[1]
filt = sys.argv[2].lower() if len(sys.argv) > 2 else None

data = json.load(io.open(path, encoding="utf-8"))
feats = data.get("features", [])

# ---- index every frontend file by basename, and count its lines
files = {}
for root, _, names in os.walk(FE):
    if "node_modules" in root or "\\.next" in root:
        continue
    for n in names:
        if n.endswith((".ts", ".tsx")):
            files.setdefault(n, []).append(os.path.join(root, n))

def line_count(p):
    try:
        with io.open(p, encoding="utf-8", errors="replace") as fh:
            return sum(1 for _ in fh)
    except Exception:
        return 0

# ---- routes declared in api.php
routes = set()
api = io.open(os.path.join(BASE, "routes", "api.php"), encoding="utf-8", errors="replace").read()
api = "\n".join("" if l.strip().startswith("//") else l for l in api.split("\n"))
for m in re.finditer(r"Route::(get|post|put|patch|delete|any)\s*\(\s*'([^']+)'", api):
    routes.add((m.group(1).upper(), "/" + m.group(2).lstrip("/")))
for m in re.finditer(r"Route::(?:resource|apiResource)\s*\(\s*'([^']+)'", api):
    base = "/" + m.group(1).lstrip("/")
    for v in ("GET", "POST", "PUT", "PATCH", "DELETE"):
        routes.add((v, base))
        routes.add((v, base + "/{id}"))

def route_exists(verb, uri):
    """
    Segment-wise match, treating a {param} on EITHER side as a wildcard.

    Both directions matter, and an earlier version only handled one:
      - the route may be generic where the inventory is concrete
        (route /competency/library/kasa/{type} vs cited .../kasa/knowledge)
      - the inventory may be generic where the route is concrete
        (cited /competency/library/{resource} vs route .../skills)
    Matching only literally produced 24 false failures out of 159 - a 15%
    "error rate" that was entirely the checker's. (Rule R1.)
    """
    uri = "/" + uri.split("?")[0].lstrip("/")
    # an inventory URI may list alternatives: /library/skills|jobroles/{id}
    variants = [uri]
    if "|" in uri:
        head, tail = uri.split("|", 1)
        pre = head.rsplit("/", 1)[0]
        first = head.rsplit("/", 1)[1]
        rest = tail.split("/", 1)
        alts = [first] + [rest[0]]
        suffix = "/" + rest[1] if len(rest) > 1 else ""
        variants = ["%s/%s%s" % (pre, a, suffix) for a in alts]

    for v_uri in variants:
        want = [s for s in v_uri.split("/") if s != ""]
        for (v, r) in routes:
            if v != verb:
                continue
            have = [s for s in r.split("/") if s != ""]
            if len(have) != len(want):
                continue
            ok = True
            for a, b in zip(want, have):
                if a.startswith("{") or b.startswith("{"):
                    continue          # wildcard on either side
                if a != b:
                    ok = False
                    break
            if ok:
                return True
    return False

rows = []
for f in feats:
    raw = str(f.get("line") or "")
    m = re.search(r"([\w.-]+\.(?:tsx|ts)):(\d+)", raw)
    entry = {"element": f.get("element", ""), "line": raw, "api": str(f.get("api") or ""),
             "fileOk": None, "lineOk": None, "apiOk": None, "why": []}

    if filt and (not m or filt not in m.group(1).lower()):
        continue

    if not m:
        entry["fileOk"] = False
        entry["why"].append("no parseable file:line")
    else:
        fname, ln = m.group(1), int(m.group(2))
        cands = files.get(fname)
        if not cands:
            entry["fileOk"] = False
            entry["why"].append("file not found: " + fname)
        else:
            entry["fileOk"] = True
            n = max(line_count(c) for c in cands)
            entry["lineOk"] = ln <= n
            if not entry["lineOk"]:
                entry["why"].append("line %d > %d in %s" % (ln, n, fname))

    # The `api` field uses compound human notation, not a single canonical string:
    #   "GET /competency/employee-options; POST/PUT with user_id_target + jobrole"
    #   "POST or PUT /competency/development-plans"
    #   "POST/PUT due_date"          <- names a PAYLOAD FIELD, not an endpoint
    #   "n/a (reads ?competency_id= from URL)"
    # Parsing it naively produced 9 false failures on this unit and 24 on the
    # previous one. Extract every (verb, /uri) pair and require them all. (R4)
    a = entry["api"].strip()
    if a and not re.match(r"^\s*(none|n/a|-)\b", a, re.I):
        pairs = []
        for am in re.finditer(r"\b(GET|POST|PUT|PATCH|DELETE)(?:\s*/\s*(?:GET|POST|PUT|PATCH|DELETE))*"
                              r"(?:\s+or\s+(?:GET|POST|PUT|PATCH|DELETE))*\s+(/[^\s;,)]+)", a, re.I):
            verbs = re.findall(r"GET|POST|PUT|PATCH|DELETE", am.group(0)[:am.start(1) - am.start() + 40], re.I)
            uri = am.group(2).split("?")[0].rstrip(".,;")
            for v in (verbs or [am.group(1)]):
                pairs.append((v.upper(), uri))
        if pairs:
            missing = [(v, u) for (v, u) in pairs if not route_exists(v, u)]
            entry["apiOk"] = not missing
            if missing:
                entry["why"].append("route not declared: " +
                                    ", ".join("%s %s" % (v, u) for v, u in missing))
        # no (verb, /uri) pair at all -> the field describes a payload or a query
        # param, not an endpoint. Not checkable, and not an error.
    rows.append(entry)

tot = len(rows)
bad = [r for r in rows if r["why"]]
chk = collections.Counter()
for r in rows:
    for k in ("fileOk", "lineOk", "apiOk"):
        if r[k] is True: chk[k + "_pass"] += 1
        elif r[k] is False: chk[k + "_FAIL"] += 1

print("rows examined: %d   (filter: %s)" % (tot, filt or "none"))
print()
for k in ("fileOk", "lineOk", "apiOk"):
    p, f = chk.get(k + "_pass", 0), chk.get(k + "_FAIL", 0)
    if p + f:
        print("  %-8s pass=%-4d FAIL=%-4d  (%.1f%% pass)" % (k, p, f, p / (p + f) * 100))
print()
print("rows with >=1 failed check: %d of %d  (%.1f%%)" % (len(bad), tot, len(bad) / tot * 100 if tot else 0))
if bad:
    print("\nFAILURES:")
    for r in bad[:40]:
        print(("  - %-52s %s" % (r["element"][:52], "; ".join(r["why"]))).encode("ascii","replace").decode())
