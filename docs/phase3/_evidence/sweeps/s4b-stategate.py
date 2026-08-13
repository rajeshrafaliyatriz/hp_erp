"""S-4b: state gates never satisfied - the dead-panel signature.
A useState<X|null> whose setter is ONLY ever called with null/false/undefined.
This is what the import-graph method (S-4) structurally cannot see."""
import io,os,re
FE=r"C:\Users\MILAN\Downloads\g2gv0"
out=[]
for root,_,names in os.walk(FE):
    if "node_modules" in root or "\.next" in root: continue
    for n in names:
        if not n.endswith(".tsx"): continue
        p=os.path.join(root,n); txt=io.open(p,encoding="utf-8",errors="replace").read()
        for m in re.finditer(r"const\s*\[\s*(\w+)\s*,\s*(set\w+)\s*\]\s*=\s*useState(?:<[^>]*>)?\(\s*([^)]{0,30})",txt):
            getter,setter,init=m.group(1),m.group(2),m.group(3).strip()
            # BUG 1 (found by hand-check): useState(true) starts SATISFIED.
            if init and not re.match(r"^(null|false|undefined|\)|$)",init): continue
            # BUG 2 (found by hand-check): the setter passed BY REFERENCE is a real
            # call site -- onSelect={setSelected}, .then(setSelected), onChange={setX}.
            if re.search(r"(?:=\{\s*"+setter+r"\s*\}|\.then\(\s*"+setter+r"\s*\)|\(\s*"+setter+r"\s*[,)])",txt): continue
            calls=re.findall(setter+r"\s*\(\s*([^)]{0,40})",txt)
            calls=[c.strip() for c in calls]
            real=[c for c in calls if c and not re.match(r"^(null|false|undefined|\)|$)",c)]
            # gate must actually be USED as a render condition
            gated=re.search(r"(?:open=\{\s*Boolean\(\s*"+getter+r"|open=\{\s*"+getter+r"\b|\{\s*"+getter+r"\s*&&)",txt)
            if calls and not real and gated:
                out.append((n,getter,setter,len(calls),txt.count("\n",0,m.start())+1))
print("state gates never satisfied:",len(out))
for n,g,s,c,ln in out: print("  %-40s :%-5d %s / %s  (%d calls, all null-ish)"%(n,ln,g,s,c))
