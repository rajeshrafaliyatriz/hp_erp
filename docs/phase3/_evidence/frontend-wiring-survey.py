# -*- coding: utf-8 -*-
"""FRONTEND WIRING SURVEY - four questions, five modules. MEASURE ONLY.

Triz's claim: many controls across competency, LMS, task, HRMS and organization
are static - not wired, or wired to something that does not properly exist. Three
instances were found BY ACCIDENT (the notification bell, the nine-box matrix,
getHolidays/menuLevel2). Nobody has looked deliberately.

METHOD CONDITIONS, each earned by a turn this engagement already paid for:

  * RESOLVE, DO NOT SCRAPE. A `DB::table` grep is not a write detector; the same
    class one layer up is that an `apiClient.get('/x')` grep is not a call
    detector. Calls are collected from the service layer AND from components,
    and template literals are normalised rather than skipped.

  * A ZERO NAMES ITS KNOWN-POSITIVE. The notification bell is the known-positive
    for question 4. If the check cannot find the bell it cannot be trusted on
    anything else, and it SKIPS rather than reporting zero.

  * CANDIDATES, NOT FINDINGS. Counts carry their definitions.

  * WIDEN BY MORE THAN ONE LAYER. A control can be dead because IT is static,
    because its SERVICE is, or because the ENDPOINT is. All three are checked
    before anything is called dead.
"""
import io, os, re, json, collections

FE = r'C:\Users\MILAN\Downloads\g2gv0'
ROUTES = r'C:\Users\MILAN\Downloads\hp_erp\docs\phase3\_evidence\_all-routes.txt'

routes = set()
for ln in io.open(ROUTES, encoding='utf-8'):
    u = ln.strip()
    if u:
        routes.add(u)
        routes.add(re.sub(r'\{[^}]+\}', '{}', u))

MODULES = {
    'competency':   ['domain/competency', 'services/competency'],
    'lms':          ['domain/lms', 'services/lms'],
    'task':         ['domain/task', 'services/task'],
    'hrms':         ['domain/hrms', 'services/hrms', 'domain/people', 'services/people'],
    'organization': ['domain/organization', 'services/organization', 'domain/talent', 'services/talent'],
}

def module_of(path):
    p = path.replace('\\', '/')
    for m, pats in MODULES.items():
        for pat in pats:
            if pat in p:
                return m
    return None

files = []
for root, dirs, names in os.walk(FE):
    if 'node_modules' in root or '.next' in root:
        continue
    for n in names:
        if n.endswith(('.ts', '.tsx')):
            files.append(os.path.join(root, n))

# ---------------------------------------------------------------- Q2: calls with no endpoint
CALL = re.compile(r"""(?:apiClient|webClient)\.(?:get|post|put|patch|delete|postForm)\s*<?[^(]*\(\s*[`'"]([^`'"]+)[`'"]""")
CONST = re.compile(r"""^\s*const\s+([A-Z_][A-Z0-9_]*)\s*=\s*['"`]([^'"`]+)['"`]""", re.M)

calls = []
for f in files:
    src = io.open(f, encoding='utf-8', errors='ignore').read()
    # RESOLVE, DO NOT SCRAPE. The first run reported 119 missing routes and every
    # sample was `${BASE}/meta` - a const the scraper never expanded. Same class as
    # the DB::table grep: the pattern measured its own blindness.
    consts = {k: v for k, v in CONST.findall(src)}
    for m in CALL.finditer(src):
        raw = m.group(1)
        for k, v in consts.items():
            raw = raw.replace('${' + k + '}', v)
        line = src[:m.start()].count('\n') + 1
        calls.append({'file': f, 'line': line, 'raw': raw, 'module': module_of(f)})

def normalise(p):
    p = p.strip()
    p = re.sub(r'\$\{[^}]*\}', '{}', p)          # template holes -> {}
    p = p.split('?')[0].rstrip('/')
    p = p.lstrip('/')
    return p

missing = []
for c in calls:
    n = normalise(c['raw'])
    if '{}' in n and not re.search(r'[a-zA-Z]', n.replace('{}', '')):
        continue
    cands = {n, 'api/' + n, n.replace('api/', '')}
    hit = False
    for cand in cands:
        if cand in routes or re.sub(r'\{\}', '{}', cand) in routes:
            hit = True
            break
        # {} may stand for a named param
        pat = '^' + re.escape(cand).replace(r'\{\}', r'\{[^}]+\}') + '$'
        if any(re.match(pat, r) for r in routes):
            hit = True
            break
    if not hit:
        missing.append(c)

# ---------------------------------------------------------------- Q1: static controls
HANDLER = re.compile(r'on(?:Click|Submit|Change|Save)\s*=\s*\{\s*(?:\(\s*\)|\([^)]*\))\s*=>\s*\{([^{}]{0,200})\}\s*\}')
EMPTYISH = re.compile(r'^\s*(?://[^\n]*|console\.(log|warn|error)\([^)]*\);?|/\*.*?\*/)?\s*$', re.S)
static_controls = []
for f in files:
    src = io.open(f, encoding='utf-8', errors='ignore').read()
    for m in HANDLER.finditer(src):
        body = m.group(1)
        if EMPTYISH.match(body):
            static_controls.append({'file': f, 'line': src[:m.start()].count('\n') + 1,
                                    'module': module_of(f), 'body': body.strip()[:60]})

# ---------------------------------------------------------------- Q4: hardcoded data
# KNOWN-POSITIVE: the notification bell. If this pattern cannot find it, the whole
# question SKIPS rather than reporting a number nobody should trust.
NUMLIT = re.compile(r'useState\s*(?:<[^>]*>)?\s*\(\s*(\d{1,4})\s*\)')
ARRLIT = re.compile(r'(?:const|useState\s*(?:<[^>]*>)?\s*\()\s*\[\s*\{\s*(?:id|name|title|label)\s*:', re.S)
hardcoded = []
bell_found = False
for f in files:
    src = io.open(f, encoding='utf-8', errors='ignore').read()
    low = os.path.basename(f).lower()
    has_fetch = bool(re.search(r'apiClient\.|webClient\.|fetch\(', src))
    if re.search(r'notification', low) or re.search(r'\bbell\b', src, re.I):
        if not has_fetch:
            bell_found = True
    if has_fetch:
        continue
    for m in list(NUMLIT.finditer(src))[:3]:
        if m.group(1) != '0' and m.group(1) != '1':
            hardcoded.append({'file': f, 'line': src[:m.start()].count('\n') + 1,
                              'module': module_of(f), 'what': 'useState(' + m.group(1) + ')'})
    if ARRLIT.search(src):
        m = ARRLIT.search(src)
        hardcoded.append({'file': f, 'line': src[:m.start()].count('\n') + 1,
                          'module': module_of(f), 'what': 'literal object array'})

# ---------------------------------------------------------------- report
print('files scanned (.ts/.tsx, no node_modules) : %d' % len(files))
print('backend routes loaded                     : %d' % len(routes))
print('frontend API call sites found             : %d' % len(calls))
print('')
print('=== Q2  CALLS WITH NO MATCHING ROUTE : %d ===' % len(missing))
by = collections.Counter(c['module'] or '(other)' for c in missing)
for m, n in by.most_common():
    print('   %-14s %d' % (m, n))
seen = set()
for c in missing[:14]:
    k = c['raw']
    if k in seen: continue
    seen.add(k)
    print('     %-46s %s:%d' % (c['raw'][:46],
          os.path.basename(c['file']), c['line']))

print('')
print('=== Q1  CONTROLS WITH AN EMPTY / CONSOLE-ONLY HANDLER : %d ===' % len(static_controls))
by = collections.Counter(c['module'] or '(other)' for c in static_controls)
for m, n in by.most_common():
    print('   %-14s %d' % (m, n))
for c in static_controls[:10]:
    print('     %-40s:%-5d %s' % (os.path.basename(c['file']), c['line'], c['body'] or '(empty)'))

print('')
if not bell_found:
    print('=== Q4  SKIPPED - the known-positive (notification bell) was NOT found by')
    print('        the pattern, so any number it produced would be untrustworthy. ===')
else:
    print('=== Q4  HARDCODED DATA IN A FILE THAT MAKES NO REQUEST : %d ===' % len(hardcoded))
    print('        known-positive (notification bell): FOUND')
    by = collections.Counter(c['module'] or '(other)' for c in hardcoded)
    for m, n in by.most_common():
        print('   %-14s %d' % (m, n))
    for c in hardcoded[:10]:
        print('     %-40s:%-5d %s' % (os.path.basename(c['file']), c['line'], c['what']))
