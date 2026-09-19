"""
Print a source file with EVERY comment removed.

═══════════════════════════════════════════════════════════════════════════════
WHY THIS IS NOT A SED ONE-LINER
═══════════════════════════════════════════════════════════════════════════════

`check-settings-guards.sh` stripped comments so a check could not be satisfied by
the prose that DESCRIBES the thing being checked - a real hole that let a
documented-but-unimplemented fix pass twice.

Its stripper handled two shapes: `// line` and the ` * continuation` of a JSDoc
block. It did not handle the third, which this codebase uses constantly:

    {/*
      THE FRAME.

      `touch-none` is required, not cosmetic: without it the browser claims
      the gesture for page scrolling...
    */}

Those continuation lines start with plain text, not `*`, so they survived - and a
mutation test proved it: deleting `touch-none` from the actual className left the
check passing, because the words were still there in the paragraph explaining why
it mattered.

A block comment spans lines, which is precisely what sed is bad at and what a
two-line Python script is good at. Order matters: block comments first, because a
`//` inside a block is not a line comment, and a `/*` inside a `//` line is not a
block.

Run:  python strip-comments.py <file>
"""

import re
import sys

if len(sys.argv) < 2:
    sys.stderr.write("usage: strip-comments.py <file>\n")
    sys.exit(2)

try:
    source = open(sys.argv[1], encoding="utf-8").read()
except OSError as caught:
    sys.stderr.write("cannot read %s: %s\n" % (sys.argv[1], caught))
    sys.exit(2)

# Block comments, including the JSX `{/* ... */}` form. Newlines are preserved so
# line numbers in any surrounding tooling still line up.
def blank_out(match):
    return re.sub(r"[^\n]", " ", match.group(0))

source = re.sub(r"/\*.*?\*/", blank_out, source, flags=re.S)
source = re.sub(r"//[^\n]*", "", source)

sys.stdout.reconfigure(encoding="utf-8", errors="replace", newline="\n")
sys.stdout.write(source)
