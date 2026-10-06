# -*- coding: utf-8 -*-
"""OVERLAP PASS over the open BUILD rows. MEASURE ONLY - writes nothing but a report.

Method: every item row in 08-connection-plan.md's tier tables is parsed. For the
rows still open, every SYMBOL the row names (controller file, php class, table,
route file) is extracted from ALL of the row's cells - not just the title. Two
rows overlap when they name the same symbol.

This is a pattern, not a finding (R6): naming the same symbol is a CANDIDATE for
shared work, and each pair still has to be read.
"""
import io, re, collections

P = r'C:\Users\MILAN\Downloads\hp_erp\docs\phase3\08-connection-plan.md'
lines = io.open(P, encoding='utf-8').read().split('\n')

ROW = re.compile(r'^\|\s*(?:\*\*)?([A-Z]{1,3}-\d{2})(?:\*\*)?\s*\|(.*)$')

# symbols: php files, controller/class names, snake_case tables, routes/*.php
SYM = re.compile(
    r'`([A-Za-z0-9_/\\.-]+\.php)`'
    r"|`([a-z][a-z0-9_]{4,})`"
    r'|`([A-Za-z][A-Za-z0-9_]*(?:Controller|Service|Projector|Reactor))`'
)

rows = {}
for ln in lines:
    m = ROW.match(ln)
    if not m:
        continue
    iid, rest = m.group(1), m.group(2)
    cells = [c.strip() for c in rest.split('|')]
    while cells and not cells[-1]:          # trailing '|' leaves an empty tail
        cells.pop()
    status = cells[-1] if cells else ''
    if not status:
        raise SystemExit('REFUSING: row %s has no status cell - the parse is wrong' % iid)
    syms = set()
    for g in SYM.findall(ln):
        s = next((x for x in g if x), None)
        if s:
            syms.add(s.split('/')[-1].split('\\')[-1])
    if iid in rows:                      # a row can appear twice; union it
        rows[iid][0] |= syms
        if 'Not started' not in status:
            rows[iid][1] = status
    else:
        rows[iid] = [syms, status]

OPEN = {i: v for i, v in rows.items()
        if 'Not started' in v[1] or 'open' in v[1].lower()}

print('item rows parsed         : %d' % len(rows))
print('still open by status col : %d' % len(OPEN))
print('')

# noise words that are not work-bearing symbols
STOP = {'phase', 'tenant', 'nothing', 'customer', 'readiness', 'pending', 'estimate',
        'right', 'rights', 'matrix', 'exists', 'needs', 'small', 'medium', 'large',
        'competency', 'skill', 'skills', 'course', 'courses', 'status', 'value'}

idx = collections.defaultdict(set)
for iid, (syms, _) in OPEN.items():
    for s in syms:
        if s.lower() not in STOP:
            idx[s].add(iid)

shared = {s: ids for s, ids in idx.items() if len(ids) > 1}
print('=== SYMBOLS NAMED BY MORE THAN ONE OPEN ROW ===')
if not shared:
    print('  none')
for s in sorted(shared, key=lambda x: (-len(shared[x]), x)):
    print('  %-42s %s' % (s, ' '.join(sorted(shared[s]))))

print('')
print('=== ROWS DECLARING A DEPENDENCY ON ANOTHER ROW (blocks / blocked-by cells) ===')
DEP = re.compile(r'\b([A-Z]{1,3}-\d{2})\b')
for ln in lines:
    m = ROW.match(ln)
    if not m or m.group(1) not in OPEN:
        continue
    cells = [c.strip() for c in m.group(2).split('|')]
    if len(cells) < 7:
        continue
    for c in cells[4:6]:                  # blocks, blocked-by
        for d in DEP.findall(c):
            if d != m.group(1):
                print('  %-6s -> %-6s  (%s)' % (m.group(1), d, c[:46]))

print('')
print('=== OPEN ROWS NAMING NO SYMBOL AT ALL (span unmeasurable from the plan) ===')
for iid in sorted(OPEN):
    if not (OPEN[iid][0] - STOP):
        print('  %-6s %s' % (iid, OPEN[iid][1][:60]))
