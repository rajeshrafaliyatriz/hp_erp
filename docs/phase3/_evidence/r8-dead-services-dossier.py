# -*- coding: utf-8 -*-
"""R8 DOSSIER for the 40 dead calls, GROUPED BY WHAT THE DECISION IS.

Two whole-file questions (lmsService, talentService) and two per-method lists
(taskService, hrmsService).

CALLERS ARE COUNTED BY RESOLVED IMPORT, never by member name. Matching
`.getCourses(` found three different objects and scored a dead method as
user-reachable; the rule has paid five times now and lives in _lib.py.

AND UP TO 23 OF THE 40 MAY NOT BE DELETIONS: the cause split was
never-existed 17, typo/rename 16, moved 7. A renamed or moved route means the
method should be REPOINTED, which is not an R8 decision at all.
"""
import io, os, re, sys, collections, difflib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

FE = r'C:\Users\MILAN\Downloads\g2gv0'
ROUTES = os.path.join(os.path.dirname(os.path.abspath(__file__)), '_all-routes.txt')
routes = [l.strip() for l in io.open(ROUTES, encoding='utf-8') if l.strip()]
routeset = set(routes) | {re.sub(r'\{[^}]+\}', '{}', r) for r in routes}

SERVICES = {
    'lmsService':    'services/lms/index.ts',
    'talentService': 'services/talent/index.ts',
    'taskService':   'services/task/index.ts',
    'hrmsService':   'services/hrms/index.ts',
}

files = []
for root, dirs, names in os.walk(FE):
    if 'node_modules' in root or '.next' in root:
        continue
    for n in names:
        if n.endswith(('.ts', '.tsx')):
            files.append(os.path.join(root, n))
SRC = {f: io.open(f, encoding='utf-8', errors='ignore').read() for f in files}

CALL = re.compile(r"""(?:apiClient|webClient)\.(?:get|post|put|patch|delete|postForm)\s*<?[^(]*\(\s*[`'"]([^`'"]+)[`'"]""")
CONST = re.compile(r"""^\s*const\s+([A-Z_][A-Z0-9_]*)\s*=\s*['"`]([^'"`]+)['"`]""", re.M)
METH = re.compile(r"""^\s{2,}(\w+)\s*[:(]""", re.M)

def normalise(p):
    return re.sub(r'\$\{[^}]*\}', '{}', p.strip()).split('?')[0].rstrip('/').lstrip('/')

def route_exists(n):
    for c in {n, 'api/' + n, n.replace('api/', '')}:
        if c in routeset:
            return True
        pat = '^' + re.escape(c).replace(r'\{\}', r'\{[^}]+\}') + '$'
        if any(re.match(pat, r) for r in routes):
            return True
    return False

def cause(n):
    """never existed | typo/rename -> X | moved -> X"""
    close = difflib.get_close_matches(n, routes, n=1, cutoff=0.82) or \
            difflib.get_close_matches('api/' + n, routes, n=1, cutoff=0.82)
    if close:
        return 'typo/rename', close[0]
    tail = n.split('/')[-1]
    if tail and '{}' not in tail:
        hits = [r for r in routes if r.split('/')[-1] == tail]
        if hits:
            return 'moved', hits[0]
    return 'never existed', ''

for obj, rel in SERVICES.items():
    path = os.path.join(FE, *rel.split('/'))
    if not os.path.exists(path):
        print('%s: FILE NOT FOUND at %s' % (obj, rel))
        continue
    src = SRC.get(path, io.open(path, encoding='utf-8', errors='ignore').read())
    consts = dict(CONST.findall(src))

    # every method and the paths it calls
    methods = collections.OrderedDict()
    marks = [(m.group(1), m.start()) for m in METH.finditer(src)]
    for m in CALL.finditer(src):
        raw = m.group(1)
        for k, v in consts.items():
            raw = raw.replace('${' + k + '}', v)
        owner = None
        for name, pos in marks:
            if pos < m.start():
                owner = name
            else:
                break
        methods.setdefault(owner, []).append(normalise(raw))

    # CALLERS BY RESOLVED IMPORT
    total_callers = 0
    caller_files = []
    for f, s in SRC.items():
        if f == path:
            continue
        if not re.search(r"import[^\n]*\b" + obj + r"\b[^\n]*from", s):
            continue
        if re.search(r'\b' + obj + r'\.\w+\s*\(', s):
            total_callers += 1
            caller_files.append(os.path.basename(f))

    dead, live = [], []
    for meth, paths in methods.items():
        if meth is None:
            continue
        bad = [p for p in paths if not route_exists(p)]
        called = len(re.findall(r'\b' + obj + r'\.' + re.escape(meth) + r'\s*\(',
                                ''.join(s for f, s in SRC.items() if f != path)))
        (dead if bad else live).append((meth, bad, called))

    print('=' * 78)
    print('%s   (%s)' % (obj, rel))
    print('   methods total          : %d   dead: %d   live: %d'
          % (len(methods) - (1 if None in methods else 0), len(dead), len(live)))
    print('   FILES IMPORTING IT AND CALLING IT : %d   %s'
          % (total_callers, ', '.join(caller_files[:4])))
    if not dead:
        print('   no dead methods')
        continue
    print('   %-26s %-9s %-14s %s' % ('METHOD', 'CALLERS', 'CAUSE', 'PATH -> nearest real route'))
    for meth, bad, called in dead:
        for p in bad:
            c, near = cause(p)
            print('   %-26s %-9d %-14s %s%s' % (meth, called, c, p,
                  ('   ->  ' + near) if near else ''))
