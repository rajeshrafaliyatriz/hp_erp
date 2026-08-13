import io, os
D = r"C:\Users\MILAN\Downloads\hp_erp\docs\phase3"
p = os.path.join(D, "08-connection-plan.md")
t = io.open(p, encoding="utf-8").read()

t = t.replace("## SLICE 1 \u2014 \"One job role, one employee, one visible gap\"",
"""## \u26a0\ufe0f SLICES 1 AND 2 SWAPPED \u2014 2026-08-07

**Answered from the dependency graph, not preference.** `F-06` (tri-state rights)
is blocked by **nothing**; `X-01` (populate the matrix) is blocked only by `F-06`;
`X-02` only by `X-01`. **No path reaches `F-01`.** The rights work touches
`tblgroupwise_rights_g2g`, `tblindividual_rights` and `tblmenumaster_g2g` \u2014
menu-and-profile tables. Menu rights decide **which screens**; the join tables
decide **which rows**. Different graphs.

**So the roles slice is independent and ships first.** It is visible in one deploy
and lands well before the largest migration in the plan.

**Numbering below is unchanged to keep cross-references stable.** Read the order as:
**SLICE 2 \u2192 SLICE 1 \u2192 SLICE 3 \u2192 4 \u2192 5.**

---

## SLICE 1 \u2014 \"One job role, one employee, one visible gap\" \u00b7 *(runs SECOND)*""")

t = t.replace("## SLICE 2 \u2014 \"Roles mean something\"",
              "## SLICE 2 \u2014 \"Roles mean something\" \u00b7 **\u2b50 RUNS FIRST**")

t = t.replace("**Still missing:** the capability chain is one role deep; no learning loop.\n**Gate:** X-01 needs the before/after diff reviewed by Triz before rollout.",
"""**Still missing:** the whole capability chain \u2014 this slice makes roles mean
something, not capability. **Gate:** X-01 needs the before/after menu diff reviewed
by Triz before rollout.

**Why it now runs first:** independent of every join table, visible in one deploy,
and it closes `G-SEC-07` \u2014 today the rights matrix is uniform, so **Employee sees
1,657 menus against Admin's 1,500**. That inversion is demonstrable on a screen
without a single migration.""")

# C32 subsumed
t = t.replace("6. Rename the job role to *Registered Nurse*. **The mapping survives.** *(Today it silently detaches \u2014 this is the moment that shows what was fixed.)*",
"""6. Rename the job role to *Registered Nurse*. **The mapping survives.** *(Today it silently detaches \u2014 this is the moment that shows what was fixed.)*

> **This step SUBSUMES C32.** The "before" half of the demo \u2014 rename a job role and
> report how many of the 85,663 task rows and 79,295 skill rows stop resolving \u2014
> **is** C32's worked example. **C32 is removed from the queue as a separate item so
> it is not run twice.**""")

io.open(p, "w", encoding="utf-8").write(t)

# drop C32 from the queue
p = os.path.join(D, "00-progress.md"); t = io.open(p, encoding="utf-8").read()
t = t.replace("3. **C32** \u2014 the worked example: rename a job role in tenant 7, report what detaches, revert",
              "3. ~~**C32** worked example~~ \u2014 **SUBSUMED** by Slice 1's demo step 6, which is the same rename. Removed so it is not run twice")
io.open(p, "w", encoding="utf-8").write(t)
print("slices swapped; C32 subsumed")
