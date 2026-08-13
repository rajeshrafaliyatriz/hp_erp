# -*- coding: utf-8 -*-
"""Q1 + Q4: dead controls and hardcoded data, with THE BELL as the acceptance test.

THE ACCEPTANCE TEST IS THE BELL'S ORIGINAL FORM. Every pattern written so far
would have missed it: it did not use `onChange={() => {}}`, it rendered a status
and a badge with no request behind either. Its docblock in
components/shell/notifications-menu.tsx preserves what it was, and that record is
the only reason the shape can be tested at all - X-06 repaired the instance, so
the defect itself is gone.

A QUESTION CAN OUTLIVE THE DEFECT THAT PROMPTED IT. Q4 was modelled on the bell
and the bell no longer qualifies.

IT ALSO LIVED IN gtg-header.tsx AND gtg-header-base.tsx AT ONCE - dead in two
places, looking maintained in both - so duplicates are counted SEPARATELY. One
fixed copy does not fix the other.

CANDIDATES, NOT FINDINGS. Definitions are attached to every count.
"""
import io, os, re, sys, collections

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from _lib import makes_request, makes_request_self_test

# THE HELPER REFUSES TO BE FORGOTTEN. Its own self-test runs first, through the
# same function this script calls, and nothing proceeds if it fails.
_fault = makes_request_self_test()
if _fault:
    raise SystemExit('REFUSING: the shared request detector is unsound - ' + _fault)

FE = r'C:\Users\MILAN\Downloads\g2gv0'

files = []
for root, dirs, names in os.walk(FE):
    if 'node_modules' in root or '.next' in root:
        continue
    for n in names:
        if n.endswith('.tsx'):
            files.append(os.path.join(root, n))

def strip_comments(src):
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    return re.sub(r'(?<!:)//[^\n]*', '', src)

# ---------------------------------------------------------------- Q1
# A control is dead if its handler does nothing - INLINE or BY NAME.
INLINE = re.compile(r'on(?:Click|Submit|Change|Save|Select)\s*=\s*\{\s*\(\s*[^)]*\)\s*=>\s*\{\s*\}\s*\}')
NAMED  = re.compile(r'on(?:Click|Submit|Change|Save|Select)\s*=\s*\{\s*(\w+)\s*\}')
# the named handler's own definition, empty or console-only
def handler_is_dead(src, name):
    # THE BODY MAY BE ON ONE LINE. The first version required a newline before the
    # closing brace, so it could not see `const h = () => { console.log(x) }` -
    # and the acceptance gate caught that before any number was produced.
    m = re.search(r'(?:const|function)\s+' + re.escape(name) +
                  r'\s*(?:=\s*)?(?:async\s*)?\([^)]*\)\s*(?:=>\s*)?\{(.*?)\}', src, re.S)
    if not m:
        return False
    body = strip_comments(m.group(1)).strip()
    return body == '' or re.fullmatch(r'(console\.\w+\([^)]*\);?\s*)+', body) is not None

def q1_scan(src):
    """Return (inline_hits, named_hits)."""
    code = strip_comments(src)
    inline = len(INLINE.findall(code))
    named = 0
    for m in NAMED.finditer(code):
        if handler_is_dead(code, m.group(1)):
            named += 1
    return inline, named

# ---------------------------------------------------------------- Q4
# Hardcoded DATA: a status word or a count rendered with no request in the file.
STATUS = re.compile(r'>\s*(?:You(?:\'|&apos;)?re all caught up|All caught up|No new notifications|New)\s*<')
BADGE  = re.compile(r'>\s*\{?\s*(?:\d{1,3})\s*\}?\s*<\s*/\s*(?:span|Badge|div)')
SINGLE_OPT = re.compile(r'options\s*=\s*\{\s*\[\s*\{[^\]]*\}\s*\]\s*\}')
# HAS_REQ REMOVED. It matched `apiClient.` literally and therefore flagged
# notifications-menu.tsx - the bell - which fetches through a SERVICE import. The
# one repaired component in the codebase was scored as hardcoded data.
# Fifth instance of resolve-do-not-match, and the fourth was written in a script
# whose header quotes the rule. It is now makes_request() in _lib.py.

def q4_scan(src):
    code = strip_comments(src)
    if makes_request(code):
        return 0
    n = 0
    n += len(STATUS.findall(code))
    n += len(SINGLE_OPT.findall(code))
    return n

# ---------------------------------------------------------------- THE GATE
# The bell's ORIGINAL form, reconstructed from its own docblock.
BELL_BEFORE = '''
export function NotificationsMenu() {
  return (
    <div>
      <button aria-label="Notifications">
        <Bell />
        <span className="badge">New</span>
      </button>
      <div className="menu">
        <p>You&apos;re all caught up</p>
      </div>
    </div>
  )
}
'''
# A LIVE handler that must NOT be flagged, through the same pipeline.
LIVE = '''
export function Real() {
  const save = async () => { await apiClient.post('/x', body); await reload() }
  return <button onClick={save}>Save</button>
}
'''
DEAD_NAMED = '''
export function Fake() {
  const handleExport = () => { console.log('export') }
  return <button onClick={handleExport}>Export</button>
}
'''

fault = []
if q4_scan(BELL_BEFORE) == 0:
    fault.append("Q4 cannot see the bell's original form")
i, nm = q1_scan(DEAD_NAMED)
if nm == 0:
    fault.append('Q1 cannot see a handler that calls a named do-nothing function')
i, nm = q1_scan(LIVE)
if i or nm:
    fault.append('Q1 flags a LIVE handler')
if q4_scan(LIVE) != 0:
    fault.append('Q4 flags a file that makes a request')

if fault:
    print('SKIPPED - the patterns failed their own acceptance tests:')
    for f in fault:
        print('   ' + f)
    print('\nA count from these would be worthless. Nothing is reported.')
    raise SystemExit(0)

print("ACCEPTANCE: the bell's original form is seen, a named do-nothing handler is")
print("seen, a live handler and a fetching file are NOT flagged.\n")

q1 = []
q4 = []
for f in files:
    src = io.open(f, encoding='utf-8', errors='ignore').read()
    inl, nmd = q1_scan(src)
    if inl or nmd:
        q1.append((f, inl, nmd))
    k = q4_scan(src)
    if k:
        q4.append((f, k))

def mod(p):
    p = p.replace('\\', '/')
    for m in ['competency', 'lms', 'task', 'hrms', 'talent', 'organization', 'people', 'shell']:
        if '/' + m in p:
            return m
    return '(other)'

print('=== Q1  DEAD CONTROLS  (handler empty or console-only, inline OR by name) ===')
print('    files: %d   inline: %d   named: %d   TOTAL: %d'
      % (len(q1), sum(x[1] for x in q1), sum(x[2] for x in q1),
         sum(x[1] + x[2] for x in q1)))
for m, n in collections.Counter(mod(f) for f, a, b in q1).most_common():
    print('      %-14s %d file(s)' % (m, n))
for f, a, b in sorted(q1, key=lambda x: -(x[1] + x[2]))[:10]:
    print('      %-52s inline:%d named:%d' % (os.path.basename(f), a, b))

print('\n=== Q4  HARDCODED DATA  (status word or single-option list, NO request in file) ===')
print('    files: %d   instances: %d' % (len(q4), sum(x[1] for x in q4)))
for m, n in collections.Counter(mod(f) for f, k in q4).most_common():
    print('      %-14s %d file(s)' % (m, n))
for f, k in sorted(q4, key=lambda x: -x[1])[:10]:
    print('      %-52s %d' % (os.path.basename(f), k))
