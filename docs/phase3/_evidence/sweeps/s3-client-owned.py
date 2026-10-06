"""S-3: client supplies a field the SERVER should own. HYPOTHESIS list (R4)."""
import io,os,re
ROOT=r"C:\Users\MILAN\Downloads\hp_erp\app"
# fields the server must own
OWNED=r"(approve_status|approval_status|verification_status|is_approved|status|role|user_profile_id|profile_id|is_admin|created_by|sub_institute_id|user_id)"
hits=[]
for root,_,names in os.walk(ROOT):
    for n in names:
        if not n.endswith(".php"): continue
        p=os.path.join(root,n)
        for i,line in enumerate(io.open(p,encoding="utf-8",errors="replace"),1):
            # 'owned_field' => $request->input(...) / $request->x  inside an array literal
            m=re.search(r"'"+OWNED+r"'\s*=>\s*\$request->(?:input\(|get\(|)['\"]?([a-z_]*)",line,re.I)
            if m:
                hits.append((n,i,m.group(1),line.strip()[:110]))
print("raw hits:",len(hits))
import collections
c=collections.Counter(h[2].lower() for h in hits)
for k,v in c.most_common(): print("  %-22s %d"%(k,v))
print()
for h in hits:
    if h[2].lower() in ("approve_status","approval_status","verification_status","role","user_profile_id","is_admin"):
        print("  %-42s:%-5d %s"%(h[0],h[1],h[3]))
