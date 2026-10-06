# -*- coding: utf-8 -*-
"""THE 40 DEAD CALLS, SPLIT BY WHETHER ANYTHING CALLS THE METHOD.

"Fix 40 calls" is the wrong framing. The verified instance - hrmsService.checkIn -
had CORRECT SIBLINGS IN THE SAME OBJECT, so this is per-method drift and each has
its own cause. A fix-list repairs symptoms without showing why a service is
half-real.

THE SPLIT THAT MATTERS MOST IS NOT WHY THE ROUTE IS MISSING. It is whether
anything calls the method: a dead call in a method nobody calls is a different
problem from a dead call behind a live button, and only the second is visible to
a user.

CATEGORIES (candidates, not verdicts):
  false positive  a real route the normaliser cannot match
  typo/rename     a route exists under a near-identical name
  moved           a route exists with the same tail under a different prefix
  never existed   nothing resembling it in 1,217 routes

METHOD CONDITIONS carried from what this engagement has paid for:
  * RESOLVE, DO NOT SCRAPE - path constants are expanded before matching
  * the caller search covers components AND app AND other services, because a
    service method may be called by another service
  * counts carry their definitions; nothing here is called a finding
"""
import io, os, re, collections, difflib

FE = r'C:\Users\MILAN\Downloads\g2gv0'
ROUTES = r'C:\Users\MILAN\Downloads\hp_erp\docs\phase3\_evidence\_all-routes.txt'

routes = [ln.strip() for ln in io.open(ROUTES, encoding='utf-8') if ln.strip()]
routeset = set(routes)
for r in list(routes):
    routeset.add(re.sub(r'\{[^}]+\}', '{}', r))

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
# the service method that encloses a call: `name: (args) => apiClient...`
METH = re.compile(r"""^\s{2,}(\w+)\s*:\s*(?:async\s*)?\(""", re.M)

def normalise(p):
    p = re.sub(r'\$\{[^}]*\}', '{}', p.strip()).split('?')[0].rstrip('/').lstrip('/')
    return p

def exists(n):
    for cand in {n, 'api/' + n, n.replace('api/', '')}:
        if cand in routeset:
            return True
        pat = '^' + re.escape(cand).replace(r'\{\}', r'\{[^}]+\}') + '$'
        if any(re.match(pat, r) for r in routes):
            return True
    return False

def enclosing_method(src, pos):
    """The service-object key whose value contains this call."""
    best = None
    for m in METH.finditer(src):
        if m.start() < pos:
            best = m.group(1)
        else:
            break
    return best

def callers_of(method, defining_file):
    """Anything referencing `.method(` outside the file that defines it."""
    n = 0
    where = []
    pat = re.compile(r'\.' + re.escape(method) + r'\s*\(')
    for f, src in SRC.items():
        if f == defining_file:
            continue
        if pat.search(src):
            n += 1
            if len(where) < 2:
                where.append(os.path.basename(f))
    return n, where

dead = []
for f in files:
    src = SRC[f]
    consts = dict(CONST.findall(src))
    for m in CALL.finditer(src):
        raw = m.group(1)
        for k, v in consts.items():
            raw = raw.replace('${' + k + '}', v)
        n = normalise(raw)
        if '{}' in n and not re.search(r'[a-zA-Z]', n.replace('{}', '')):
            continue
        if exists(n):
            continue
        meth = enclosing_method(src, m.start())
        ncall, where = callers_of(meth, f) if meth else (0, [])
        dead.append({'file': f, 'path': n, 'method': meth,
                     'callers': ncall, 'where': where})

# ---- categorise --------------------------------------------------------
tails = collections.defaultdict(list)
for r in routes:
    tails[r.split('/')[-1]].append(r)

for d in dead:
    n = d['path']
    close = difflib.get_close_matches(n, routes, n=1, cutoff=0.85) or \
            difflib.get_close_matches('api/' + n, routes, n=1, cutoff=0.85)
    tail = n.split('/')[-1]
    if close:
        d['cat'] = 'typo/rename'
        d['note'] = close[0]
    elif tail and tail in tails and '{}' not in tail:
        d['cat'] = 'moved'
        d['note'] = tails[tail][0]
    else:
        d['cat'] = 'never existed'
        d['note'] = ''

print('dead calls (route absent from %d) : %d' % (len(routes), len(dead)))
print('')
print('=== SPLIT BY WHETHER ANYTHING CALLS THE METHOD ===')
live = [d for d in dead if d['callers'] > 0]
orphan = [d for d in dead if d['callers'] == 0]
print('   BEHIND A LIVE CALLER   %d   <- reachable by a user' % len(live))
print('   NOTHING CALLS IT       %d   <- dead code, invisible' % len(orphan))
print('')
print('=== SPLIT BY CAUSE ===')
for c, n in collections.Counter(d['cat'] for d in dead).most_common():
    print('   %-16s %d' % (c, n))
print('')
print('=== BEHIND A LIVE CALLER - the ones a user can reach ===')
for d in sorted(live, key=lambda x: -x['callers'])[:16]:
    print('   %-34s %-22s %-14s callers:%d %s' % (
        d['path'][:34], (d['method'] or '?')[:22], d['cat'], d['callers'],
        ','.join(d['where'])))
print('')
print('=== NOTHING CALLS IT (first 10) ===')
for d in orphan[:10]:
    print('   %-34s %-22s %s' % (d['path'][:34], (d['method'] or '?')[:22], d['cat']))
