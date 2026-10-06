"""
HOW MANY `required` FIELDS EACH SETTINGS SECTION DRAWS.

Split out of `check-settings-guards.sh` because counting this in shell was wrong
twice over, and both mistakes made the check report failures that did not exist:

  `grep -c ... || echo 0`  emits two lines when grep finds nothing — grep exits 1
                           AND prints 0 — so every numeric comparison after it
                           was a syntax error that read as a failed check.

  a loose pattern          matched the word "required" in prose. The hint
                           "letters and numbers are always required" counted as
                           a required field, in a section that has none.

Reading the actual `<Field>` tags removes both. Attributes span lines, so a tag
is matched with DOTALL rather than line by line.

Prints one `<section> <required> <with_error>` line per file, LF-terminated.

`with_error` exists because the first version of the caller only asked whether a
section contained ANY field error. A mutation test proved that blind: deleting
one of delivery's four `error=` props left three, and the check still passed.
Counting both means a field that loses its validation is visible.
"""

import glob
import os
import re
import sys

# LF, not the CRLF `print` emits on Windows.
#
# The shell caller reads these lines with `read -r f n` and compares the count
# numerically. A trailing CR makes that `[ "0<CR>" -eq 0 ]`, which is a syntax
# error bash reports as a failed test — so every section came back a failure.
# Fixing it at the source beats a `tr -d` in the caller that is itself easy to
# get wrong.
sys.stdout.reconfigure(newline="\n")

# A Windows path, not the Git-Bash `/c/...` form: this runs under CPython for
# Windows, where `/c/Users/...` globs to nothing and this prints no lines at all
# — which the caller would read as "no section has a required field".
SECTIONS = sys.argv[1] if len(sys.argv) > 1 else (
    "C:/Users/MILAN/Downloads/g2gv0/components/settings/sections"
)

# `required` as a whole prop: not preceded by a word character or hyphen, and
# followed by `=`, whitespace, or the closing `>`. Excludes `data-required`,
# `notRequired`, and every appearance of the word in prose.
PROP = re.compile(r"(?<![\w-])required(?=[\s=>])")

# An `error=` prop on the same tag. Its value may be `{shown.x}`, `{photoError}`
# or anything else — what matters is that the field has somewhere to say no.
ERROR = re.compile(r"(?<![\w-])error=")

paths = sorted(glob.glob(os.path.join(SECTIONS, "*-section.tsx")))

if not paths:
    # Loudly, and on stderr with a non-zero exit. Printing nothing would let the
    # caller conclude every section passed — which is exactly what happened when
    # this file had a syntax error: the shell script reported 0 WRONG and exited
    # 0 while checking nothing at all.
    sys.stderr.write("no section files found under %s\n" % SECTIONS)
    sys.exit(1)

for path in paths:
    body = open(path, encoding="utf-8").read()
    tags = [t for t in re.findall(r"<Field\b[^>]*>", body, re.S) if PROP.search(t)]
    required = len(tags)
    with_error = sum(1 for tag in tags if ERROR.search(tag))
    name = os.path.basename(path)[: -len("-section.tsx")]
    print("%s %d %d" % (name, required, with_error))
