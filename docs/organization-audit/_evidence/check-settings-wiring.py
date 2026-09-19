"""
EVERY ENDPOINT THE SETTINGS AREA CALLS, CHECKED AGAINST THE ROUTE TABLE.

═══════════════════════════════════════════════════════════════════════════════
THE FAILURE THIS CATCHES
═══════════════════════════════════════════════════════════════════════════════

A frontend calling a path that does not exist, or calling it with the wrong verb,
fails at RUN time - in the browser, as an empty section with a red banner.
Nothing earlier sees it: `tsc` type-checks the response shape and never the URL
string, `eslint` has no opinion about paths, and `next build` compiles a typo'd
endpoint exactly as happily as a correct one.

This product has already shipped that bug in a subtler form. The audit trail was
permanently empty because the frontend sent `type: 'api'` as a query parameter
into an endpoint that had its own `type` filter, so the server ran
`where a.type = 'api'` and returned nothing. Right path, right verb, HTTP 200,
zero rows - and the evidence script passed, because it only asserted that a list
came back.

═══════════════════════════════════════════════════════════════════════════════
WHY THE CALLS ARE READ FROM THE SOURCE AND NOT LISTED HERE
═══════════════════════════════════════════════════════════════════════════════

The first version of this file carried a hand-written list of the calls. It
reported a failure on its very first run - `PUT /account/password` against a
route registered only for POST - and the bug was in the LIST. The frontend sends
POST; the route is POST; the transcription was wrong.

A check whose input is retyped by hand tests the typing. So the pairs are parsed
out of the frontend source instead, and the transcription is gone.

The risk that trades for is the one this directory exists to avoid: a scraper
that matches nothing reports a clean run. Two guards against that - the parse
must find at least `MINIMUM` calls, and it must find the handful of endpoints
named in `MUST_INCLUDE`, which are load-bearing enough that their absence means
the parse broke rather than the product changed.

Run:
    php artisan route:list --json > <scratch>/routes.json
    python Docs/organization-audit/_evidence/check-settings-wiring.py <scratch>/routes.json
"""

import glob
import json
import os
import re
import sys

# Windows stdout is cp1252 and this file's prose uses box drawing. Without this
# a completely clean run still ends in UnicodeEncodeError, which reads as a
# failure of the thing being checked rather than of the printing.
sys.stdout.reconfigure(encoding="utf-8", errors="replace")

ROUTES = sys.argv[1] if len(sys.argv) > 1 else "routes.json"
FE = "C:/Users/MILAN/Downloads/g2gv0"

# Where the settings area's HTTP calls live.
#
# THE FIRST VERSION OF THIS LIST WAS TOO NARROW, WHICH IS THE SAME BUG AS A
# HAND-WRITTEN CALL LIST WEARING A DIFFERENT HAT.
#
# It named three files, and reported "19 CORRECT, 0 WRONG across the 19 endpoints
# the settings area calls" - a true sentence about a subset, read as a statement
# about the whole. Two service modules the settings area depends on were outside
# it entirely: `module-enablement.ts` (Modules) and `employee-directory.ts`
# (People & access), and neither was checked.
#
# Whole directories now, not named files, so a service module added tomorrow is
# picked up rather than silently skipped. `employee-directory.ts` is shared with
# the Employee Directory screen, so this covers a little more than Settings -
# which is the right direction for the error to go in.
SOURCES = [
    FE + "/components/settings/**/*.tsx",
    FE + "/services/account/*.ts",
    FE + "/services/organization/*.ts",
]

# Below this the parse has broken, not the product. The floor sits under the
# current count with room for a section to be removed legitimately.
MINIMUM = 20

# If any of these is missing, the parse is wrong: they are the endpoints without
# which the settings area does not function at all.
MUST_INCLUDE = {
    ("GET", "/account/me"),
    ("PUT", "/account/preferences"),
    ("GET", "/organization/settings"),
}

# Optional, for the report only - what breaks if the endpoint is missing.
WHY = {
    "/account/me": "every section: profile, preferences, role, choices",
    "/account/password": "Sign-in & security: change your own password",
    "/account/preferences": "Preferences and Notifications autosave",
    "/account/preferences/device": "Preferences: stop using this device's settings",
    "/account/preferences/promote": "Preferences: use these on all my devices",
    "/account/profile": "Profile: name, contact and the photo (multipart)",
    "/account/sessions": "Sign-in & security: the device list",
    "/account/sessions/{}": "Sign-in & security: sign out one device",
    "/employees-management/pending-access": "People & access: who cannot sign in",
    "/employees-management/{}/invite": "People & access: send or resend an invite",
    "/organization/audit": "Audit: the trail",
    "/organization/delivery": "Email & SMS: the stored mailbox",
    "/organization/delivery/test": "Email & SMS: send myself a test",
    "/organization/modules": "Modules: what is switched on",
    "/organization/roles": "Roles & access: the table",
    "/organization/roles/{}": "Roles & access: the inline editors",
    "/organization/settings": "Organisation defaults and Security policy",
}

# `apiClient.put<...>('/path'` / `apiClient.get(`/path/${id}`` and so on.
CALL = re.compile(
    r"apiClient\s*\.\s*(get|post|put|patch|delete|putForm|postForm)\s*"
    r"(?:<[^(]*?>)?\s*\(\s*[`'\"]([^`'\"]+)[`'\"]",
    re.S,
)


def normalise(uri):
    """`api/account/{id}`, `/account/${id}` and `/account/:id` all compare equal."""
    uri = uri.split("?")[0]                       # a query string is not the route
    uri = "/" + uri.lstrip("/")
    if uri.startswith("/api/"):
        uri = uri[4:]
    uri = re.sub(r"\$\{[^}]*\}", "{}", uri)       # template interpolation
    uri = re.sub(r"\{[^}]*\}", "{}", uri)         # Laravel's {id}
    uri = re.sub(r":[A-Za-z_][A-Za-z0-9_]*", "{}", uri)
    return uri.rstrip("/") or "/"


def reachable_modules():
    """
    Which service modules any SCREEN can actually reach.

    ═══════════════════════════════════════════════════════════════════════════
    WHY REACHABILITY MATTERS TO A WIRING CHECK
    ═══════════════════════════════════════════════════════════════════════════

    This check found three endpoints that cannot resolve:

        GET  ajax_user_profiles_g2g
        GET  ajax_groupwiserights_g2g
        POST save_groupwiserights_g2g

    They live in `services/organization/role-permissions.ts`, which calls them
    through `apiClient` with no leading slash and no `/user/` prefix - so they
    would resolve to `<host>/apiajax_user_profiles_g2g`, twice wrong. The real
    routes are `user/ajax_user_profiles_g2g` on the web group.

    And none of it runs. The Role & Permissions screen reaches those routes
    correctly through `services/navigation/menu-rights.ts`
    (`webClient.get('/user/ajax_user_profiles_g2g')`); the module above is
    imported by nothing except the barrel that re-exports it. It is dead code
    with the more obvious name, sitting beside the working implementation - a trap
    for whoever wires the next thing to Role & Permissions, but not a defect
    anybody can hit today.

    A check that reports three permanent failures gets ignored, and then it stops
    catching the real one. So an unreachable module's calls are reported in their
    own group and excluded from the pass/fail count - named, not hidden, and not
    crying wolf.

    Reachability is approximated by whether any file under `components/`, `hooks/`
    or `app/` imports the module by path. Deliberately crude: it can only ever
    say "reachable" too often, which keeps a live call inside the strict count.
    """
    importers = []
    for pattern in ("/components/**/*.tsx", "/components/**/*.ts",
                    "/hooks/**/*.ts", "/app/**/*.tsx"):
        importers.extend(glob.glob(FE + pattern, recursive=True))

    blob = []
    for path in importers:
        try:
            blob.append(open(path, encoding="utf-8").read())
        except OSError:
            pass
    blob = "\n".join(blob)

    # BY EXPORTED SYMBOL, NOT BY FILE NAME.
    #
    # Matching the file name was wrong, and wrong in the direction that made this
    # whole group pointless: `services/organization/role-permissions.ts` looked
    # reachable because `lib/role-permissions.ts` and `hooks/use-role-permissions.ts`
    # both contain the string "role-permissions", so an import of either matched.
    # The dead module stayed in the strict count and the check kept failing.
    #
    # A module is reachable if a screen names something it EXPORTS. That is exact,
    # and it still works through the barrels - a component importing
    # `rolePermissionsService` from '@/services' mentions the symbol either way.
    EXPORTED = re.compile(
        r"export\s+(?:const|function|async\s+function|class)\s+([A-Za-z_$][\w$]*)"
    )

    reachable = set()
    for pattern in SOURCES:
        for path in glob.glob(pattern, recursive=True):
            # A component file is always reachable - it IS a screen.
            if path.endswith(".tsx"):
                reachable.add(path)
                continue

            src = open(path, encoding="utf-8").read()
            symbols = set(EXPORTED.findall(src))

            if not symbols:
                # Nothing exported: nothing to reach it by, and nothing to check.
                continue

            # WORD BOUNDARIES, WRITTEN AS \b AND NOT AS A BACKSPACE.
            #
            # This line briefly held a literal 0x08 control character where each
            # word boundary belongs, because the escape collapsed on its way into
            # the file. The regex then matched nothing, so EVERY service module
            # looked unreachable, so 54 of the 62 endpoints were quietly dropped
            # from the count - and the script printed "8 CORRECT, 0 WRONG" and
            # exited 0.
            #
            # A check that skips most of its subject and reports success is the
            # exact failure this directory exists to prevent, which is why
            # main() now also refuses to pass if the reachable count collapses.
            BOUNDARY = r"\b"
            if any(
                re.search(BOUNDARY + re.escape(name) + BOUNDARY, blob) for name in symbols
            ):
                reachable.add(path)

    return reachable


def collect_calls():
    """Every (verb, path) issued, tagged with whether a screen can reach it."""
    found = {}
    live = reachable_modules()

    for pattern in SOURCES:
        for path in glob.glob(pattern, recursive=True):
            src = open(path, encoding="utf-8").read()
            # Comments in this codebase quote endpoint paths while explaining
            # them, so they are stripped first or the parse invents calls.
            src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
            src = re.sub(r"//.*", "", src)

            for verb, raw in CALL.findall(src):
                # `putForm` is a PUT with a multipart body (Laravel method
                # spoofing puts `_method=PUT` in the form), and `postForm` a POST.
                method = {"putform": "PUT", "postform": "POST"}.get(
                    verb.lower(), verb.upper()
                )
                key = (method, normalise(raw))
                entry = found.setdefault(key, {"files": set(), "reachable": False})
                entry["files"].add(os.path.basename(path))
                if path in live:
                    entry["reachable"] = True

    return found


def main():
    calls = collect_calls()

    # ── the anti-vacuous guards, before anything is compared ────────────────
    if len(calls) < MINIMUM:
        print("  WRONG    parsed only %d calls, expected at least %d" % (len(calls), MINIMUM))
        print("           the parser has broken - NOTHING below was checked")
        return 1

    # A THIRD GUARD, because the second one was not enough.
    #
    # `MINIMUM` and `MUST_INCLUDE` both check the PARSE. Neither noticed when the
    # reachability filter broke and dropped 54 of 62 endpoints from the strict
    # count: the parse was fine, so both guards passed, and the report said
    # "8 CORRECT, 0 WRONG" - a clean bill of health for a check that had stopped
    # looking at almost everything.
    #
    # So the surviving count is guarded too. Almost every module here is reachable
    # (one is dead), and if that ratio collapses the filter is broken rather than
    # the product.
    reached = sum(1 for key in calls if calls[key]["reachable"])
    if reached < len(calls) // 2:
        print("  WRONG    only %d of %d parsed calls were judged reachable" % (reached, len(calls)))
        print("           the reachability filter has broken - most of this check was skipped")
        return 1

    missing_anchor = MUST_INCLUDE - set(calls)
    if missing_anchor:
        for method, path in sorted(missing_anchor):
            print("  WRONG    the parse did not find %s %s, which must exist" % (method, path))
        print("           the parser has broken - NOTHING below was checked")
        return 1

    try:
        routes = json.load(open(ROUTES, encoding="utf-8"))
    except Exception as caught:                   # noqa: BLE001
        print("  WRONG    could not read the route table: %s" % caught)
        print("           NOTHING was checked")
        return 1

    if not routes:
        print("  WRONG    the route table is empty - NOTHING was checked")
        return 1

    table = {}
    for route in routes:
        table.setdefault(normalise(route["uri"]), set()).update(route["method"].split("|"))

    print("  %d calls parsed from the settings area, %d routes registered" % (len(calls), len(table)))
    print()

    correct = wrong = 0
    unreachable = []

    def verdict(method, path, verbs):
        """CORRECT, or the reason it is not."""
        if verbs and method in verbs:
            return None
        if verbs:
            # A route registered for another verb is a DIFFERENT bug from a
            # missing one, and the fix is different: it says the endpoint was
            # built and the two sides disagree about how to reach it.
            return "registered, but only for %s" % ",".join(sorted(verbs - {"HEAD"}))
        return "NO SUCH ROUTE"

    for (method, path) in sorted(calls):
        entry = calls[(method, path)]
        verbs = table.get(path)
        problem = verdict(method, path, verbs)
        why = WHY.get(path, ", ".join(sorted(entry["files"])))

        if not entry["reachable"]:
            # Dead code. Listed below rather than counted - see reachable_modules.
            if problem:
                unreachable.append((method, path, problem, why))
            continue

        if problem:
            print("  WRONG    %-6s %-40s %s" % (method, path, problem))
            wrong += 1
        else:
            print("  CORRECT  %-6s %-40s %s" % (method, path, why))
            correct += 1

    print()
    print("  %d CORRECT, %d WRONG across the endpoints a screen can actually reach"
          % (correct, wrong))

    if unreachable:
        print()
        print("  -- broken, but in code NO SCREEN IMPORTS " + "-" * 25)
        print("     Not counted above: nobody can hit these today. They are a trap")
        print("     for the next person, not a live fault. Delete or fix at leisure.")
        for method, path, problem, why in unreachable:
            print("       %-6s %-40s %s" % (method, path, problem))
            print("              in %s" % why)

    return 1 if wrong else 0


if __name__ == "__main__":
    sys.exit(main())
