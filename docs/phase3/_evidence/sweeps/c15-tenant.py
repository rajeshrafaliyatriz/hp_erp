"""C15: the 64 sub_institute_id-from-request hits. Inside or outside the F-01 fix?
R6: CANDIDATES. Every hit is hand-classified before any number is quoted."""
import io,os,re,collections
ROOT=r"C:\Users\MILAN\Downloads\hp_erp\app"
rows=[]
for root,_,names in os.walk(ROOT):
    for n in names:
        if not n.endswith(".php"): continue
        p=os.path.join(root,n)
        txt=io.open(p,encoding="utf-8",errors="replace").read()
        uses_trait = bool(re.search(r"use\s+(ResolvesApiIdentity|ResolvesLmsIdentity|Resolves\w+Context)\b",txt))
        for i,line in enumerate(txt.split("\n"),1):
            if re.search(r"'sub_institute_id'\s*=>\s*\$request->",line):
                # is it a WRITE (inside insert/update array) or a read/filter?
                rows.append((os.path.relpath(p,ROOT),i,uses_trait,line.strip()[:95]))
print("raw hits:",len(rows))
byfile=collections.Counter(r[0] for r in rows)
print("files:",len(byfile))
print("\n--- IN a trait-using controller (should be using apiTenantId) ---")
for r in sorted(rows):
    if r[2]: print("  %-62s :%-5d %s"%(r[0],r[1],r[3]))
print("\n--- NOT trait-using (legacy / non-API) ---")
c=collections.Counter(r[0] for r in rows if not r[2])
for f,n in c.most_common(): print("  %-62s %d"%(f,n))
