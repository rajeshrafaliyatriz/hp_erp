# User flows — the short roles

**Written 2026-08-11, against what exists.**

Roles here get a **difference list**, not a flow. The test applied to each:

> **Does this role have a JOURNEY of its own, or the same journey with fewer
> rights?** The first needs a flow; the second needs a difference list.

A short section stating the difference honestly beats a full flow repeating
another role's routes with a narrower scope column.

---

## `hr_manager` — **⚠ RETRACTED AND REWRITTEN 2026-08-11**

### THE FIRST VERSION OF THIS SECTION WAS WRONG

It said the two roles *"differ by one menu"* and were *"in practice one role"*.
**That came from comparing `can_view` only.** The roles are distinguished, and the
distinction lives in the WRITE columns I did not read:

    administrator      view=81   write=49
    hr_manager         view=80   write=35
    view-only-to-admin  1        WRITE-only-to-admin  14

**Same wrong-population family as the two zeros** — I measured a real column,
correctly, and drew a conclusion about a different one. A role's power is what it
can WRITE.

### THE BOUNDARY IS SEEDED, AND IT MATCHES THE SPEC

The 14 menus an Administrator can write and an HR Manager cannot:

    Role & Permissions · Permision · Integration
    Projects & Workstreams · Dependencies & Workstreams
    Status Management · Priority Management
    Agent Dashboard · Create Agent · Run Log · Analytics
    Multi-Agent · Reflection · Agentic Library

**Configuration, every one.** That is exactly the spec's line — *"Admin should own
configuration, not daily HR operations"* — and `03-rbac-matrix.md` §3.1 carries it
too: Role & Permissions and Group-wise rights are `V` for HR Mgr and `V C E D` for
Admin.

`hr_executive` is also distinguished: 13 view / 12 write menus separate it from
`hr_manager`. **The seed lost nothing.**

### ⛔ THE REAL DEFECT — **THE API DOES NOT CONSULT THE RIGHTS MATRIX**

All 16 endpoints are guarded `profile:admin,hr`. **The route layer knows only
"admin or HR", and the rights matrix that distinguishes them is never asked.**

So an HR Manager can today call:

    POST /readiness/gates/acknowledge     switch a capability off, tenant-wide
    POST /competency/framework-import/commit   commit a customer's framework
    POST /reporting-line/bulk             rewrite the reporting line

**Every one is a configuration act, and the rights matrix says HR cannot do
configuration.** Two authorization systems disagree, and the API uses the one that
cannot tell the roles apart.

**THIS IS NOT A ROLE-DESIGN QUESTION.** The design is decided, written in the spec,
present in the matrix and seeded into the data. **It is an enforcement gap:** the
guard is coarser than the permission model behind it, and the finer model has no
effect on any API call.

An HR Manager who can switch a capability off for the whole tenant is a
configuration act wearing a people-ops name — and it will not survive a customer's
permissions review.

### The fix, filed not built

`profile:admin,hr` on the configuration endpoints should become `profile:admin`,
or better, the guard should consult `tblgroupwise_rights_g2g` so the matrix is the
single source. Which of those is a design decision. **The wide-reaching acts —
gate acknowledgement, framework commit, reporting-line rewrite, Integration,
terminology — belong to the Administrator alone.**

### Where this role stands

**Works today:** everything in `administrator.md` §7 — which is itself the
problem, not the reassurance.

**Dead-ended on:** the same five items as the Administrator.

**Depends on:** the guard-narrowing item above, the only one unique to this role.

---

## `hr_executive`, `recruiter`, `executive`, `auditor`

**Not yet written.** They were scoped as short sections and remain open:

| role | scope agreed |
|---|---|
| `hr_executive` | say only where it differs from `hr_manager` |
| `recruiter` | four screens plus its own capability view |
| `executive` | read-only: what they see and why they cannot act |
| `auditor` | read-only: what they see and why they cannot act |

**A measurement is needed first for each**, the same one that settled
`hr_manager`: route guards and menu rights against the role they are nearest to.
Writing them from the plan rather than from that measurement is what X-17 exists
to avoid — and in `hr_manager`'s case the measurement changed the answer from
"narrower scope" to "one menu".
