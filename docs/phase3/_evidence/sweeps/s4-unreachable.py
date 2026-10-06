"""S-4: built components never imported anywhere. HYPOTHESIS list (R4)."""
import io,os,re,collections
FE=r"C:\Users\MILAN\Downloads\g2gv0"
files={}
for root,_,names in os.walk(FE):
    if "node_modules" in root or "\.next" in root: continue
    for n in names:
        if n.endswith((".tsx",".ts")):
            files[os.path.join(root,n)]=io.open(os.path.join(root,n),encoding="utf-8",errors="replace").read()
alltxt="\n".join(files.values())
exported=collections.defaultdict(list)
for p,txt in files.items():
    n=os.path.basename(p)
    if n.startswith("page.") or n.startswith("layout.") or n.startswith("route."): continue
    for m in re.finditer(r"export\s+(?:default\s+)?function\s+([A-Z]\w+)",txt):
        exported[m.group(1)].append((n,txt.count("\n",0,m.start())+1,len(txt.split("\n"))))
dead=[]
for name,locs in exported.items():
    # count references to the symbol OUTSIDE its own declaration file
    refs=0
    for p,txt in files.items():
        if os.path.basename(p)==locs[0][0]: continue
        if re.search(r"\b"+name+r"\b",txt): refs+=1
    if refs==0: dead.append((name,locs[0][0],locs[0][1],locs[0][2]))
dead.sort(key=lambda x:-x[3])
print("exported components:",len(exported)," never referenced elsewhere:",len(dead))
print()
for name,f,ln,size in dead[:25]:
    print("  %-34s %-44s :%-5d  (file %d lines)"%(name,f,ln,size))
