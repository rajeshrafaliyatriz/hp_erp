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

## THE FOUR SHORT ROLES

**Written 2026-08-12.** Each opened with the route-guard and **WRITE**-rights
measurement, because that measurement has now changed the answer twice - once for
`hr_manager` (retracted) and once for `department_head` (draft corrected by probe).

    role            profile  view  write  nine-box (probed)
    hr_executive    53        67    23     200   <- full admin,hr route access
    recruiter       56         8     1     403
    executive       54        72     0     403
    auditor         55        81     0     403

---

## `hr_executive` - **the most damning of the four**

**Where it differs from `hr_manager`: in the matrix, by 12 write menus. In the
API, not at all.**

    hr_manager    view 80  write 35
    hr_executive  view 67  write 23
    difference    13 view, 12 write - a real, deliberate narrowing

`03-rbac-matrix.md` §3.1 draws it explicitly: HR Exec gets `V C E (dept)` where HR
Mgr gets `V C E D` org-wide. **Department scope versus organization scope.**

**EVERY ROUTE IS BLIND TO IT.** The alias map reads:

    'hr' => ['hr_manager', 'hr_executive']

One alias, both roles, all 32 `profile:admin,hr` routes. Probed: `hr_executive`
reaches `/api/competency/nine-box` with 200, exactly as `hr_manager` does.

**This is the alias approach failing at the precise thing it was chosen for.** It
exists to express which roles may reach a route, and here it collapses two roles
the matrix separates by twelve write permissions. **The matrix draws a real
distinction and the routes cannot see it.**

Nothing in the product reads the difference. An HR Executive is an HR Manager to
every endpoint in the system.

---

## `recruiter` - **one paragraph**

View 8 menus, write 1 (`Recruitment`), and **no route guard names it** - probed
403 on nine-box. It is the narrowest role in the product and its situation is
`department_head`'s in miniature: a matrix grant with no route that honours it.
The four screens and the capability view it was scoped for are unbuilt, and
nothing about them is blocked by anything except themselves. That is the whole
section.

---

## `executive` and `auditor` - **read-only in the matrix, no-access in the API**

    executive  view 72  write 0
    auditor    view 81  write 0

**Genuinely read-only** - zero write rights anywhere, which is what the spec
intends and what `03-rbac-matrix.md` shows (`V` and `V X` across every row).
`auditor` sees more menus than the administrator does (81 vs 81 view, 0 vs 49
write): total visibility, zero authority. That is exactly right for an auditor.

**And neither appears in any route guard.** Aliases exist for both -
`'executive' => ['executive']`, `'auditor' => ['auditor']` - and **no route uses
them.** Probed: 403 on nine-box for both.

### THIS IS THE THIRD DIRECTION OF THE SAME GAP

The matrix says *read everything*. The routes say *nothing at all*. For a
write-capable role that mismatch costs authority; **for a read-only role it costs
the entire purpose of the role.** An auditor who cannot read has no function.

Their capability today is what `department_head` has: authentication, a session, a
rendering sidebar over 72 and 81 menus, and employee-level self-service behind it.

### What they must never see

Unchanged, and one addition specific to a read-only pair: **a control that
appears actionable.** A screen rendering an edit button for a role with `write=0`
is offering something the matrix denies - and since the routes deny everything
anyway, the button would fail for a reason the user cannot connect to their role.

---

## Where these four stand

**Works today:** `hr_executive` - everything `administrator.md` §7 lists, because
the routes cannot distinguish it from `hr_manager`. The other three -
authentication, session, sidebar, employee-level self-service.

**Dead-ended on:** `G-BLOCK-01` for all four. The matrix grants each of them
something no route honours, and the guard that would honour it is built, proven,
and cannot be registered.

**Depends on:** `G-BLOCK-01` · the alias-map decision (whether `executive`,
`auditor`, `recruiter` and `department_head` get argument-sets, or whether routes
stop using aliases once the matrix is enforced - **the second makes the first
unnecessary**).

---

