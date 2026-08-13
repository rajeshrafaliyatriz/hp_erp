# -*- coding: utf-8 -*-
"""WHICH SCRIPTS WRITE, AND WHICH VERIFY BY READING THE RESULT BACK?

THE FIRST VERSION OF THIS AUDIT WAS SCOPED BY DIRECTORY. It searched
`_changes/`, found two unverified scripts, and then ADDED TWO MORE FROM MEMORY
because I happened to recall them. That is the scope error's signature: a scoped
search patched by remembered exceptions rather than re-scoped.

So this searches by WRITE VERB across every directory, and the two I remembered
must FALL OUT of it. If they do not, the search is wrong and that is the finding.

AND IT GIVES A DENOMINATOR (R27). "Two unverified" out of what?
"""
import io, os, re, collections

ROOT = r'C:\Users\MILAN\Downloads\hp_erp\docs\phase3'

# Anything that changes state: the database, or a file on disk.
WRITE = re.compile(
    r'->(?:update|insert|insertGetId|updateOrInsert|updateOrCreate|delete|truncate)\s*\('
    r'|Schema::(?:table|create|drop)'
    r'|\b(?:INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|ALTER\s+TABLE)\b'
    r'|file_put_contents\s*\('
    r"|io\.open\s*\([^)]*['\"]w['\"]"
    r'|\.write\s*\(', re.I)

# Reading the result BACK, after the write, from the thing that was written.
VERIFY = re.compile(
    r'->count\s*\(\)|->exists\s*\(\)|->value\s*\(|DB::select|SELECT\s|'
    r'hasColumn|getColumnListing|substr_count|file_get_contents', re.I)

rows = []
for dirpath, dirs, names in os.walk(ROOT):
    for n in names:
        if not n.endswith(('.php', '.py')):
            continue
        p = os.path.join(dirpath, n)
        src = io.open(p, encoding='utf-8', errors='ignore').read()
        # Comments describe writes without performing them.
        code = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
        code = re.sub(r'(?<!:)//[^\n]*', '', code)
        code = re.sub(r'^\s*#[^\n]*', '', code, flags=re.M)
        if not WRITE.search(code):
            continue
        rel = os.path.relpath(p, ROOT).replace('\\', '/')
        rows.append({'file': rel, 'writes': len(WRITE.findall(code)),
                     'verifies': len(VERIFY.findall(code))})

# KNOWN-POSITIVE: the two found by the directory-scoped search MUST appear here.
names = {r['file'].split('/')[-1] for r in rows}
missing = [x for x in ('G-NAV-01-apply.php', 'X-01-backup-rights.php') if x not in names]
if missing:
    print('SKIPPED - the search does not find scripts the previous audit did: %s' % ', '.join(missing))
    print('The population is wrong, and that is the finding. Nothing reported.')
    raise SystemExit(0)
print('KNOWN-POSITIVE: both scripts the directory-scoped audit found are in this')
print('population, so the write-verb search is at least as wide as it was.\n')

unver = [r for r in rows if r['verifies'] == 0]
print('scripts that WRITE (any directory)     : %d' % len(rows))
print('  with a post-write read in the file   : %d' % (len(rows) - len(unver)))
print('  NO read-back anywhere in the file    : %d' % len(unver))

by = collections.Counter(r['file'].split('/')[0] if '/' in r['file'] else '(root)' for r in rows)
print('\nby directory:')
for d, n in by.most_common():
    print('   %-22s %d' % (d, n))

print('\n=== NO READ-BACK ANYWHERE IN THE FILE ===')
for r in sorted(unver, key=lambda x: -x['writes']):
    print('   %-56s %d write(s)' % (r['file'], r['writes']))

print('\n=== WRITES WITH THE FEWEST VERIFICATIONS (1-2), worth a look ===')
thin = [r for r in rows if 0 < r['verifies'] <= 2]
for r in sorted(thin, key=lambda x: (x['verifies'], -x['writes']))[:10]:
    print('   %-56s %d write(s), %d read(s)' % (r['file'], r['writes'], r['verifies']))
