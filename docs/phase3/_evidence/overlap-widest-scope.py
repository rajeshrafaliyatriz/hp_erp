# -*- coding: utf-8 -*-
"""OVERLAP PASS, second population: the classification batches.

The tier table does not record spans - 37 of 51 open rows name no symbol in it.
The §1b/1c/1d batches DO: each row was classified with one line of evidence, and
that line names the file or table it was verified against.

Population = every line in the plan that names a row ID, anywhere in the file.
That is the widest scope the document supports, chosen deliberately: the last
scope error this phase was a search whose population was narrower than its claim.
"""
import io, re, collections

P = r'C:\Users\MILAN\Downloads\hp_erp\docs\phase3\08-connection-plan.md'
text = io.open(P, encoding='utf-8').read()
lines = text.split('\n')

ID = re.compile(r'\b([A-Z]{1,3}-\d{2})\b')
SYM = re.compile(r'`([A-Za-z0-9_/\\.-]{4,}?)`')

# the 24 open BUILD rows, per 00-progress.md's split (26 minus the 2 G-SEC rows closed)
CLOSED = {'S-01','S-02','S-05','O-03','LM-01','T-01','TL-02','R-05','X-01','X-04',
          'X-05','X-06','F-01','F-02','F-04','F-07','L-11','X-17','X-21','L-03',
          'L-06','L-07','X-03'}

span = collections.defaultdict(set)
for ln in lines:
    ids = set(ID.findall(ln))
    if len(ids) != 1:            # a line naming two rows attributes to neither
        continue
    iid = ids.pop()
    for s in SYM.findall(ln):
        s = s.split('/')[-1].split('\\')[-1]
        if re.match(r'^[A-Za-z][A-Za-z0-9_.]*$', s) and not s.startswith('G-'):
            span[iid].add(s)

STOP = {'sub_institute_id','tenant','null','NULL','true','false','open','BUILD','id',
        'name','title','status','type','value','count','deny','allow'}

TARGET = sorted(i for i in span if i not in CLOSED)
print('rows with any span evidence: %d' % len(TARGET))
print('')

idx = collections.defaultdict(set)
for iid in TARGET:
    for s in span[iid] - STOP:
        idx[s].add(iid)

shared = {s: ids for s, ids in idx.items() if len(ids) > 1}
print('=== SHARED SYMBOLS ACROSS OPEN ROWS  (%d) ===' % len(shared))
for s in sorted(shared, key=lambda x: (-len(shared[x]), x)):
    print('  %-40s %s' % (s, ' '.join(sorted(shared[s]))))

print('')
print('=== PER-ROW SPAN SIZE (0 = the plan records no span for it) ===')
for iid in TARGET:
    n = len(span[iid] - STOP)
    print('  %-6s %2d  %s' % (iid, n, ' '.join(sorted(list(span[iid] - STOP))[:5])))
