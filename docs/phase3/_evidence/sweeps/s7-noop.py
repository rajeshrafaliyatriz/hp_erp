"""S-7: no-op handlers / buttons wired to nothing. HYPOTHESIS list (R4)."""
import io,os,re,collections
FE=r"C:\Users\MILAN\Downloads\g2gv0"
pats={
 "empty arrow handler": r"on[A-Z]\w+=\{\s*\(\s*\)\s*=>\s*\{\s*\}\s*\}",
 "void/noop":           r"on[A-Z]\w+=\{\s*(?:noop|\(\)\s*=>\s*(?:void 0|null|undefined))\s*\}",
 "TODO in handler":     r"on[A-Z]\w+=\{[^}]{0,80}//\s*TODO",
 "console.log only":    r"on[A-Z]\w+=\{\s*\(\s*\)\s*=>\s*console\.\w+\([^)]*\)\s*\}",
 "disabled always":     r"disabled=\{\s*true\s*\}",
}
hits=collections.defaultdict(list)
for root,_,names in os.walk(FE):
    if "node_modules" in root or "\.next" in root: continue
    for n in names:
        if not n.endswith((".tsx",".ts")): continue
        txt=io.open(os.path.join(root,n),encoding="utf-8",errors="replace").read()
        for k,p in pats.items():
            for m in re.finditer(p,txt):
                hits[k].append((n,txt.count("\n",0,m.start())+1))
for k in pats:
    print("%-20s %d"%(k,len(hits[k])))
    c=collections.Counter(f for f,_ in hits[k])
    for f,v in c.most_common(6): print("     %-46s %d"%(f,v))
