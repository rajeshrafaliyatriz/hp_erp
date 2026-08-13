import io, re, os

D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"
PAT = re.compile(r"\\\\u([0-9a-fA-F]{4})")   # a literal backslash-u escape in the text

for name in ("08-connection-plan.md", "13-current-state.md", "12-gate-c-verification.md",
             "00-progress.md", "07-gap-register.md"):
    p = os.path.join(D, name)
    t = io.open(p, encoding="utf-8").read()
    n = len(PAT.findall(t))
    if not n:
        continue
    t = PAT.sub(lambda m: chr(int(m.group(1), 16)), t)
    io.open(p, "w", encoding="utf-8").write(t)
    print("%-30s decoded %d" % (name, n))
print("done")
