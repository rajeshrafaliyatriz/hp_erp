"""G-SEC-12 first pass - the proven rule applied mechanically.

  IDENTITY : created_by / updated_by / *_by / actor fields fed from input. Always.
  SUBJECT  : a field naming who the operation is ABOUT. Legitimate, leave alone.

That rule cleared PayrollController's four sites and tblgroupwise_rightsController
cleanly. Applied here first; only the ambiguous remainder is hand-read.
"""
import io, os, re, collections, json

APP = r"C:\Users\MILAN\Downloads\hp_erp\app"

# a provenance/actor column taking its value from the request
ACTOR = re.compile(r"'(created_by|updated_by|deleted_by|verified_by|reviewer_id|approved_by|actor_id|modified_by)'\s*=>\s*\$request->")
# a subject parameter - names WHO the operation is about
SUBJECT_HINT = re.compile(r"user_id_target|_target|employee_id|emp_id|candidate_id|profile_id|subject_id")

identity, subject, ambiguous = [], [], []

for root, _, names in os.walk(APP):
    for n in names:
        if not n.endswith(".php"):
            continue
        p = os.path.join(root, n)
        txt = io.open(p, encoding="utf-8", errors="replace").read()
        for i, line in enumerate(txt.split("\n"), 1):
            m = ACTOR.search(line)
            if not m:
                continue
            rec = (os.path.relpath(p, APP), i, m.group(1), line.strip()[:100])
            if SUBJECT_HINT.search(line):
                ambiguous.append(rec)      # an actor column fed by a subject-looking param
            else:
                identity.append(rec)       # provenance from input -> IDENTITY, always

print("=== G-SEC-12 first pass ===")
print("IDENTITY  (provenance from request input) :", len(identity))
print("AMBIGUOUS (needs a hand read)             :", len(ambiguous))
print()
by = collections.Counter(r[0] for r in identity)
for f, c in by.most_common():
    print("  %-58s %d" % (f, c))
print()
if ambiguous:
    print("--- AMBIGUOUS ---")
    for r in ambiguous:
        print("  %-50s:%-5d %s" % (r[0], r[1], r[3]))

json.dump({"identity": identity, "ambiguous": ambiguous},
          io.open(r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3\_evidence\gsec12-result.json",
                  "w", encoding="utf-8"), indent=1)
