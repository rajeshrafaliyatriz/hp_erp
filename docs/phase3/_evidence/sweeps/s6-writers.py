"""S-6: multiple writers to one table.  R4: this is a HYPOTHESIS list."""
import io,os,re,collections
ROOT=r"C:\Users\MILAN\Downloads\hp_erp\app"
w=collections.defaultdict(set)
for root,_,names in os.walk(ROOT):
    for n in names:
        if not n.endswith(".php"): continue
        p=os.path.join(root,n)
        txt=io.open(p,encoding="utf-8",errors="replace").read()
        # DB::table('x')-> ... insert/update/upsert/delete
        for m in re.finditer(r"DB::table\(\s*'([a-z0-9_]+)'\s*\)((?:(?!DB::table)[\s\S]){0,400})",txt,re.I):
            tbl,tail=m.group(1),m.group(2)
            if re.search(r"->\s*(insert|insertGetId|update|updateOrInsert|upsert|delete)\s*\(",tail):
                w[tbl].add(n)
        # Eloquent Model::create/update on a model file is one writer; skip
multi={t:sorted(f) for t,f in w.items() if len(f)>1}
print("tables with >1 writing file:",len(multi),"of",len(w))
for t,f in sorted(multi.items(),key=lambda x:-len(x[1]))[:25]:
    print("%-42s %2d  %s"%(t,len(f),", ".join(f[:5])))
