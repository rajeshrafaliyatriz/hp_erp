# -*- coding: utf-8 -*-
"""THE REPOINT READ - two columns, plus the route side.

A signature says which GENERATION a method belongs to. It does not say whether
anything CALLS it. Those are different questions and only the second decides what
a repoint does, so the tell is never reported alone.

    pre-convention AND dead          cleanup or repoint, no behaviour change
    pre-convention BUT STILL CALLED  a repoint CHANGES BEHAVIOUR and IS a fix
    post-convention but dead anyway  the tell misses it entirely - reported as
                                     a separate column so the miss is visible

ROUTE SIDE, per candidate:
    does a LIVE method already call the target route?  -> DUPLICATE, not repoint
    is the target the same endpoint, or plausibly named? -> read, not scored
"""
import io, os, re

FE = r'C:\Users\MILAN\Downloads\g2gv0'
ROUTES = r'C:\Users\MILAN\Downloads\hp_erp\docs\phase3\_evidence\_all-routes.txt'
routes = {l.strip() for l in io.open(ROUTES, encoding='utf-8') if l.strip()}

SERVICES = {
    'hrmsService':   'services/hrms/index.ts',
    'lmsService':    'services/lms/index.ts',
    'talentService': 'services/talent/index.ts',
    'taskService':   'services/task/index.ts',
}

SRC = {}
for root, dirs, names in os.walk(FE):
    if 'node_modules' in root or '.next' in root:
        continue
    for n in names:
        if n.endswith(('.ts', '.tsx')):
            p = os.path.join(root, n)
            SRC[p] = io.open(p, encoding='utf-8', errors='ignore').read()

CALL = re.compile(r"""apiClient\.(?:get|post|put|patch|delete|postForm)\s*<?[^(]*\(\s*['"`]([^'"`]+)['"`]""")
CONST = re.compile(r"""^\s*const\s+([A-Z_][A-Z0-9_]*)\s*=\s*['"`]([^'"`]+)['"`]""", re.M)
# a method entry:  name: (args) =>   /  name(args) {
ENTRY = re.compile(r"""^\s{2}(\w+)\s*[:(]([^\n]*)""", re.M)

def norm(p):
    return re.sub(r'\$\{[^}]*\}', '{}', p.strip()).split('?')[0].rstrip('/').lstrip('/')

def exists(n):
    for c in {n, 'api/' + n, n.replace('api/', '')}:
        if c in routes:
            return True
        pat = '^' + re.escape(c).replace(r'\{\}', r'\{[^}]+\}') + '$'
        if any(re.match(pat, r) for r in routes):
            return True
    return False

# every apiClient path that any LIVE (context-taking) method calls, anywhere
live_paths = {}
for p, src in SRC.items():
    consts = dict(CONST.findall(src))
    marks = [(m.group(1), m.start(), m.group(2)) for m in ENTRY.finditer(src)]
    for m in CALL.finditer(src):
        raw = m.group(1)
        for k, v in consts.items():
            raw = raw.replace('${' + k + '}', v)
        owner = None
        for name, pos, sig in marks:
            if pos < m.start():
                owner = (name, sig)
            else:
                break
        if owner and 'LaravelContext' in owner[1]:
            live_paths.setdefault(norm(raw), []).append(os.path.basename(p) + ':' + owner[0])

print('%-11s %-22s %-9s %-9s %-26s %s' % ('OBJECT','METHOD','no ctx?','called?','PATH','route side'))
print('-' * 118)

for obj, rel in SERVICES.items():
    path = os.path.join(FE, *rel.split('/'))
    src = SRC.get(path)
    if not src:
        continue
    consts = dict(CONST.findall(src))
    marks = [(m.group(1), m.start(), m.group(2)) for m in ENTRY.finditer(src)]
    for m in CALL.finditer(src):
        raw = m.group(1)
        for k, v in consts.items():
            raw = raw.replace('${' + k + '}', v)
        n = norm(raw)
        if exists(n):
            continue                                   # only the dead ones
        owner = None
        for name, pos, sig in marks:
            if pos < m.start():
                owner = (name, sig)
            else:
                break
        if not owner:
            continue
        meth, sig = owner
        noctx = 'LaravelContext' not in sig
        called = len(re.findall(r'\b' + obj + r'\.' + re.escape(meth) + r'\s*\(',
                                ''.join(s for f, s in SRC.items() if f != path)))
        # route side: does a LIVE method already call something for this concept?
        tail = n.split('/')[0]
        dupes = sorted({v for k, vs in live_paths.items() if tail and tail in k for v in vs})
        note = ('DUPLICATE of ' + dupes[0]) if dupes else 'no live caller of that path'
        print('%-11s %-22s %-9s %-9s %-26s %s' % (
            obj, meth[:22], 'YES' if noctx else 'no', called, n[:26], note[:44]))
