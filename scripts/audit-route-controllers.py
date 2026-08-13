"""
Verify that every controller class referenced by a route actually exists.

A route pointing at a missing class does not fail quietly. Laravel reflects on
controller classes when building the route table, so ONE bad reference throws
`ReflectionException` and takes out `php artisan route:list` and, more
importantly, `route:cache` - a standard production deployment step.

routes/lms.php had exactly that: book_listController, a class with no file and
no directory. It was found only because `route:list` crashed while debugging
something else.

Usage:  python scripts/audit-route-controllers.py
Exit 1 if any referenced controller class is missing.
"""
import io, os, re, sys, glob

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ROUTES_DIR = os.path.join(BASE, "routes")
CTRL_ROOT = os.path.join(BASE, "app", "Http", "Controllers")


def controller_exists(fqcn):
    """PSR-4: App\\Http\\Controllers\\Foo\\Bar -> app/Http/Controllers/Foo/Bar.php"""
    if not fqcn.startswith("App\\Http\\Controllers"):
        # Outside the controller tree - resolve against app/ generally.
        rel = fqcn.replace("App\\", "").replace("\\", os.sep) + ".php"
        return os.path.exists(os.path.join(BASE, "app", rel))
    rel = fqcn.replace("App\\Http\\Controllers\\", "").replace("\\", os.sep) + ".php"
    return os.path.exists(os.path.join(CTRL_ROOT, rel))


missing = []
checked = 0
route_names = {}   # name -> [file:line, ...]
resources = {}     # resource uri -> [file:line, ...]

def strip_comments(src):
    """
    Blank out commented-out code, preserving line numbers.

    Route files are full of disabled routes - the Neo4j graph endpoints alone
    account for eight. Scanning them raised eight 'missing class' reports for
    classes that exist and routes that are not registered. Line count is kept
    so reported line numbers still match the file.
    """
    src = re.sub(r"/\*.*?\*/", lambda m: "\n" * m.group(0).count("\n"), src, flags=re.S)
    out = []
    for line in src.split("\n"):
        stripped = line.lstrip()
        out.append("" if stripped.startswith(("//", "#")) else line)
    return "\n".join(out)


for path in sorted(glob.glob(os.path.join(ROUTES_DIR, "*.php"))):
    with io.open(path, "r", encoding="utf-8", errors="replace") as fh:
        src = strip_comments(fh.read())

    fname = os.path.basename(path)

    # Duplicate route names break `php artisan route:cache` outright, which is a
    # standard deploy step - and at runtime the later declaration silently
    # replaces the earlier one, so nothing looks wrong until deployment.
    for m in re.finditer(r"->name\(\s*'([^']+)'", src):
        line = src[:m.start()].count("\n") + 1
        route_names.setdefault(m.group(1), []).append("%s:%d" % (fname, line))

    # Route::resource generates a full set of .index/.store/... names, so two
    # resources on the same URI collide the same way.
    for m in re.finditer(r"Route::(?:resource|apiResource)\(\s*'([^']+)'([^;\n]*)", src, re.I):
        if "->names(" in m.group(2):
            continue  # explicitly disambiguated
        line = src[:m.start()].count("\n") + 1
        resources.setdefault(m.group(1), []).append("%s:%d" % (fname, line))

    # alias -> FQCN from the use block
    aliases = {}
    for m in re.finditer(r"^use\s+([\w\\]+)(?:\s+as\s+(\w+))?\s*;", src, re.M):
        fqcn, alias = m.group(1), m.group(2)
        aliases[alias or fqcn.split("\\")[-1]] = fqcn

    # every Name::class appearing in a route definition
    seen = set()
    for m in re.finditer(r"([\\\w]+)::class", src):
        raw = m.group(1)
        line = src[:m.start()].count("\n") + 1
        if raw.startswith("\\") or "\\" in raw:
            fqcn = raw.lstrip("\\")
        else:
            fqcn = aliases.get(raw)
            if not fqcn:
                # A bare name with no `use` import. Route files declare no
                # namespace, so PHP resolves it against the GLOBAL namespace -
                # \lmsSyllabusController - which never exists. Skipping these
                # (the first version did) hides a live 500 and a broken
                # route:cache, so they are reported.
                missing.append((os.path.basename(path), line,
                                raw + "   (bare name, no `use` import -> global namespace)"))
                checked += 1
                continue
        if not fqcn.startswith("App\\"):
            continue
        key = (fqcn, os.path.basename(path))
        if key in seen:
            continue
        seen.add(key)
        checked += 1
        if not controller_exists(fqcn):
            missing.append((os.path.basename(path), line, fqcn))

dup_names = {k: v for k, v in route_names.items() if len(v) > 1}
dup_res = {k: v for k, v in resources.items() if len(v) > 1}

print("controller class references checked : %d" % checked)
print("named routes                        : %d" % len(route_names))
print("resource declarations               : %d\n" % len(resources))

failed = False

if missing:
    failed = True
    print("MISSING CLASSES (%d) - each one breaks route:list and route:cache:\n" % len(missing))
    for f, line, fqcn in missing:
        print("  %-16s line %-5d %s" % (f, line, fqcn))
    print()

if dup_names:
    failed = True
    print("DUPLICATE ROUTE NAMES (%d) - route:cache refuses to serialise these:\n" % len(dup_names))
    for name, where in sorted(dup_names.items()):
        print("  %-42s %s" % (name, " | ".join(where)))
    print()

if dup_res:
    failed = True
    print("DUPLICATE RESOURCE URIs (%d) - each generates the same .index/.store/... names.\n"
          "Add ->names('...') to one of them, or delete the duplicate:\n" % len(dup_res))
    for uri, where in sorted(dup_res.items()):
        print("  %-42s %s" % (uri, " | ".join(where)))
    print()

if failed:
    sys.exit(1)

print("Clean: every referenced controller exists, and no route name or resource URI is declared twice.")
