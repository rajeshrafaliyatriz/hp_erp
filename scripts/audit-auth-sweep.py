"""
Per-route API authentication sweep.

Resolves every route in routes/api.php to its controller AND the specific method
it dispatches to, then reports how that method authenticates.

Per-METHOD, not per-file. An earlier per-file version reported AJAXController as
"hand-rolled" because the file contained findToken() somewhere - while the
method actually routed to, getUsersMappings(), called no guard at all and was
fully open. Per-file classification hides exactly the gaps this is looking for.

Usage:  python scripts/audit-auth-sweep.py [--verbose]
Exit 1 when any route is unauthenticated and not on the public allowlist.
"""
import io, os, re, sys, collections

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ROUTES = os.path.join(BASE, "routes", "api.php")
CTRL_ROOT = os.path.join(BASE, "app", "Http", "Controllers")
VERBOSE = "--verbose" in sys.argv

# Routes that must stay reachable without a token.
#
# Certificate verification is deliberately open: the point is that an outside
# party - an employer checking a credential - can confirm it. The random
# verification_code in the URL is itself the capability; without it the endpoint
# returns 404. It exposes only certificate metadata plus the learner's name,
# which is the whole purpose of a verifiable certificate.
PUBLIC_ALLOWLIST = {
    ("post", "/send-otp"),
    ("post", "/verify-otp"),
    ("post", "/school-setup"),
    ("post", "/user-signup"),
    ("post", "/auth/google"),
    ("get", "/lms/learning/certificates/verify/{code}"),
    ("get", "/verify/certificate/{code}"),
}

with io.open(ROUTES, "r", encoding="utf-8", errors="replace") as fh:
    routes_src = fh.read()

aliases = {}
for m in re.finditer(r"^use\s+([\w\\]+)(?:\s+as\s+(\w+))?\s*;", routes_src, re.M):
    fqcn, alias = m.group(1), m.group(2)
    aliases[alias or fqcn.split("\\")[-1]] = fqcn

route_re = re.compile(
    r"Route::(get|post|put|patch|delete|any)\s*\(\s*'([^']+)'\s*,\s*\[\s*(\w+)::class\s*,"
    r"\s*'([^']+)'\s*\]\s*\)((?:->[^;\n]*)*)\s*;"
)

routes = []
for m in route_re.finditer(routes_src):
    routes.append({
        "verb": m.group(1),
        "uri": m.group(2),
        "alias": m.group(3),
        "method": m.group(4),
        "chain": m.group(5) or "",
        "line": routes_src[:m.start()].count("\n") + 1,
    })

# The task-management group carries task.sanitize / task.permission.
#
# Brace-matched, not "everything after the prefix line". An earlier version used
# the latter and silently marked all 236 routes in the back half of api.php as
# protected - including POST /update-fcm-token, which had no guard at all and
# let anyone overwrite any user's push token. A verifier that reports safety it
# has not established is worse than no verifier.
def _brace_span(src, open_at):
    depth, j = 0, open_at
    while j < len(src):
        if src[j] == "{":
            depth += 1
        elif src[j] == "}":
            depth -= 1
            if depth == 0:
                return j
        j += 1
    return len(src) - 1


def middleware_group_spans(src):
    """
    Line ranges of every `Route::...->middleware(...)->group(function () { ... })`.

    Generic on purpose. A hardcoded task-management check missed the routes I
    had just protected by putting a middleware() on their prefix group, and
    reported them as wide open - a verifier that cries wolf gets ignored, which
    is as bad as one that stays silent.
    """
    spans = []
    for m in re.finditer(r"Route::[^;\n]*?->middleware\([^)]*\)[^;\n]*?->group\(function\s*\([^)]*\)\s*\{", src):
        open_at = src.index("{", m.end() - 1)
        close_at = _brace_span(src, open_at)
        spans.append((src[:m.start()].count("\n") + 1, src[:close_at].count("\n") + 1))
    return spans


GROUP_SPANS = middleware_group_spans(routes_src)


def in_middleware_group(line):
    return any(start < line < end for start, end in GROUP_SPANS)

GUARD_CALL = re.compile(
    r"\$this->(resolveApiIdentity|lmsIdentity|guardLmsToken|guardApiToken|guardAdmin|"
    r"guardAuthoring|contextUserId|contextTenantId|lmsTenantId|tenantId|requireUser|"
    r"\w*[Cc]ontext)\s*\("
)
FINDTOKEN = re.compile(r"PersonalAccessToken::findToken")
REQ_IDENTITY = re.compile(
    r"\$request->(?:input\(\s*'(?:user_id|sub_institute_id)'\s*\)|user_id\b|sub_institute_id\b)"
)

_src_cache = {}


def controller_source(fqcn):
    if fqcn not in _src_cache:
        rel = fqcn.replace("App\\Http\\Controllers\\", "").replace("\\", os.sep) + ".php"
        path = os.path.join(CTRL_ROOT, rel)
        if not os.path.exists(path):
            _src_cache[fqcn] = None
        else:
            with io.open(path, "r", encoding="utf-8", errors="replace") as fh:
                _src_cache[fqcn] = fh.read().replace("\r\n", "\n")
    return _src_cache[fqcn]


def method_body(src, method):
    """Extract one method body by brace matching."""
    m = re.search(r"function\s+" + re.escape(method) + r"\s*\(", src)
    if not m:
        return None
    i = src.find("{", m.end())
    if i < 0:
        return None
    depth, j = 0, i
    while j < len(src):
        if src[j] == "{":
            depth += 1
        elif src[j] == "}":
            depth -= 1
            if depth == 0:
                return src[i:j + 1]
        j += 1
    return src[i:]


def strip_comments(text):
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    return "\n".join(l for l in text.split("\n") if not l.strip().startswith("//"))


SELF_CALL = re.compile(r"\$this->(\w+)\s*\(")


def guarded_transitively(src, method, depth=0, seen=None):
    """
    True when `method` authenticates, either directly or through a helper it
    delegates to.

    Controllers routinely route thin public methods into a shared private one -
    LibraryController::skills() is just `return $this->listResource('skill',
    $request);` and it is listResource() that calls competencyContext(). Judging
    only the routed method would report 25 correctly-guarded competency routes
    as wide open.
    """
    if seen is None:
        seen = set()
    if depth > 3 or method in seen:
        return False
    seen.add(method)

    body = method_body(src, method)
    if body is None:
        return False
    body = strip_comments(body)

    if GUARD_CALL.search(body) or FINDTOKEN.search(body):
        return True

    for call in SELF_CALL.findall(body):
        if call in seen:
            continue
        if guarded_transitively(src, call, depth + 1, seen):
            return True

    return False


results = []
for r in routes:
    fqcn = aliases.get(r["alias"])
    if not fqcn:
        results.append((r, "UNRESOLVED", 0))
        continue
    src = controller_source(fqcn)
    if src is None:
        results.append((r, "MISSING FILE", 0))
        continue

    body = method_body(src, r["method"])
    if body is None:
        results.append((r, "METHOD NOT FOUND", 0))
        continue
    body = strip_comments(body)

    nreq = len(REQ_IDENTITY.findall(body))

    if "middleware" in r["chain"]:
        kind = "route-middleware"
    elif in_middleware_group(r["line"]):
        kind = "group-middleware"
    elif guarded_transitively(src, r["method"]):
        kind = "guarded"
    else:
        kind = "UNAUTHENTICATED"
    results.append((r, kind, nreq))

buckets = collections.Counter(k for _, k, _ in results)
print("Routes analysed: %d\n" % len(results))
for k in ["UNAUTHENTICATED", "route-middleware", "group-middleware", "guarded",
          "METHOD NOT FOUND", "MISSING FILE", "UNRESOLVED"]:
    if buckets.get(k):
        print("  %-18s %4d" % (k, buckets[k]))

leaks = [(r, n) for r, k, n in results if k in ("guarded", "route-middleware", "group-middleware") and n]
if leaks:
    total = sum(n for _, n in leaks)
    print("\n%d authenticated routes still read identity from the request (%d reads):"
          % (len(leaks), total))
    by_ctrl = collections.Counter()
    for r, n in leaks:
        by_ctrl[r["alias"]] += n
    for ctrl, n in by_ctrl.most_common(25):
        print("  %-45s %3d reads" % (ctrl, n))

open_routes = [r for r, k, _ in results
               if k == "UNAUTHENTICATED" and (r["verb"], r["uri"]) not in PUBLIC_ALLOWLIST]
if open_routes:
    print("\nUNAUTHENTICATED and not on the public allowlist (%d):" % len(open_routes))
    for r in open_routes:
        print("  %-6s %-42s %s::%s  (api.php:%d)"
              % (r["verb"].upper(), r["uri"], r["alias"], r["method"], r["line"]))

if VERBOSE:
    print("\nMETHOD NOT FOUND (inherited / magic / trait methods - review manually):")
    for r, k, _ in results:
        if k == "METHOD NOT FOUND":
            print("  %-6s %-42s %s::%s" % (r["verb"].upper(), r["uri"], r["alias"], r["method"]))

sys.exit(1 if open_routes else 0)
