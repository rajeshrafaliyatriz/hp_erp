"""G-SEC-12 fix - the acting user comes from the token, never from the request.

Fix shape mirrors payrollActorId() (D-004): token first, session fallback, because
these controllers serve both the API and the Blade screens.

Only the RHS of a PROVENANCE assignment is substituted. Subject parameters are not
touched - the first pass found zero ambiguous cases, so no site here names who the
operation is ABOUT.
"""
import io, os, re, json

APP = r"C:\Users\MILAN\Downloads\hp_erp\app"
IMPORT = "use App\\Http\\Controllers\\Api\\Concerns\\ResolvesApiIdentity;"

HELPER = '''
    /**
     * The ACTING user, resolved from the token and never from the request.
     *
     * G-SEC-12. created_by / updated_by were taken from request input, so a caller
     * could attribute their own write to another user and the audit trail would
     * record it as fact. A leak exposes data; this corrupts the record of who did
     * what - the evidence you would rely on when investigating a leak.
     *
     * Blocks the event store: actor_id on every event has to be trustworthy or the
     * store inherits a corrupted audit trail on day one.
     *
     * Same shape as payrollActorId (D-004): token first, session fallback.
     */
    private function g2gActorId(\\Illuminate\\Http\\Request $request): ?int
    {
        $fromToken = $this->apiUserId($request);
        if ($fromToken) {
            return $fromToken;
        }
        $fromSession = $request->session()->get('user_id');

        return is_numeric($fromSession) ? (int) $fromSession : null;
    }
'''

ACTOR_COLS = r"(created_by|updated_by|deleted_by|verified_by|reviewer_id|approved_by|actor_id|modified_by)"
# 'created_by' => $request->anything   (up to the next , or ] or ; )
ASSIGN = re.compile(r"('" + ACTOR_COLS + r"'\s*=>\s*)\$request->[A-Za-z_]+(?:\(\s*'[^']*'\s*(?:,[^)]*)?\))?")

data = json.load(io.open(r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3\_evidence\gsec12-result.json",
                         encoding="utf-8"))
files = sorted({r[0] for r in data["identity"]})

changed, skipped = [], []
for rel in files:
    p = os.path.join(APP, rel)
    src = io.open(p, encoding="utf-8", newline="").read()

    if "class " not in src:
        skipped.append((rel, "not a class"))
        continue

    new, n = ASSIGN.subn(lambda m: m.group(1) + "$this->g2gActorId($request)", src)
    if n == 0:
        skipped.append((rel, "no assignment matched"))
        continue

    if "ResolvesApiIdentity" not in new:
        m = re.search(r"^use [^\n]+;\n", new, re.M)
        if not m:
            skipped.append((rel, "no import anchor"))
            continue
        new = new[:m.end()] + IMPORT + "\n" + new[m.end():]

    if "use ResolvesApiIdentity;" not in new:
        m = re.search(r"^class\s+\w+[^\{]*\{", new, re.M)
        if not m:
            skipped.append((rel, "no class anchor"))
            continue
        new = new[:m.end()] + "\n    use ResolvesApiIdentity;\n" + new[m.end():]

    if "function g2gActorId" not in new:
        m = re.search(r"^[ \t]*use ResolvesApiIdentity;[ \t]*$", new, re.M)
        if not m:
            skipped.append((rel, "trait-use line not found"))
            continue
        new = new[:m.end()] + "\n" + HELPER + new[m.end():]

    io.open(p, "w", encoding="utf-8", newline="").write(new)
    changed.append((rel, n))

print("FILES CHANGED:", len(changed), " SITES SUBSTITUTED:", sum(n for _, n in changed))
for rel, n in changed:
    print("  %-62s %d" % (rel, n))
if skipped:
    print("\nSKIPPED (hand-read required):")
    for rel, why in skipped:
        print("  %-62s %s" % (rel, why))
